<?php

declare(strict_types=1);

namespace Inlay\Tables\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inlay\Actions\ActionRunner;
use Inlay\Tables\TablePage;
use Inlay\Tables\Exports\ExportManager;

final class TablePageController
{
    public function __invoke(Request $request, Container $container): mixed
    {
        $pageClass = $request->route()?->getAction('inlayTablePage');

        if (! is_string($pageClass) || ! is_subclass_of($pageClass, TablePage::class)) {
            throw new \LogicException('The route does not contain a valid standalone table page.');
        }

        /** @var TablePage $page */
        $page = $container->make($pageClass);

        $viewOperation = $request->query('_inlay_table_view', $request->input('_inlay_table_view'));
        if (is_string($viewOperation) && in_array($viewOperation, ['save', 'delete'], true)) {
            $name = $request->input('table', $request->query('table'));
            if (! is_string($name) || trim($name) === '') {
                throw ValidationException::withMessages(['table' => 'A valid table name is required.']);
            }

            if ($viewOperation === 'delete') {
                $viewName = $request->input('name', $request->query('name'));
                if (! is_string($viewName) || trim($viewName) === '') {
                    throw ValidationException::withMessages(['view' => 'A valid view name is required.']);
                }

                $payload = $page->deleteTableView($request, $name, $viewName);

                return $request->header('X-Inertia') === 'true'
                    ? redirect()->back(303)
                    : response()->json($payload);
            }

            $payload = $page->saveTableView($request, $name, $request->all());

            return $request->header('X-Inertia') === 'true'
                ? redirect()->back(303)
                : response()->json($payload);
        }

        $exportFormat = $request->query('_inlay_export');
        if (
            in_array($request->getMethod(), ['GET', 'POST'], true)
            && is_string($exportFormat)
            && preg_match('/^[a-z][a-z0-9-]{0,31}$/', $exportFormat) === 1
        ) {
            $name = $request->query('table');
            $action = $request->query('export');
            if (! is_string($name) || ! is_string($action)) {
                throw ValidationException::withMessages([
                    'export' => 'A valid table and export action are required.',
                ]);
            }

            return $page->exportTable(
                $request,
                $container->make(ActionRunner::class),
                $name,
                $action,
                $container->make(ExportManager::class),
            );
        }

        if ($request->boolean('_inlay_column_update')) {
            $name = $request->query('table');
            $record = $request->input('record');
            $column = $request->input('column');
            if (
                ! is_string($name)
                || (! is_string($record) && ! is_int($record))
                || ! is_string($column)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column) !== 1
            ) {
                throw ValidationException::withMessages([
                    'column' => 'Valid table, record, and column metadata are required.',
                ]);
            }

            return response()->json($page->updateTableColumn(
                $request,
                $container->make(ValidationFactory::class),
                $name,
                $record,
                $column,
                $request->input('state'),
            ));
        }

        if ($request->query('_inlay_action') !== null) {
            $name = $request->query('table');
            $action = $request->query('_inlay_action');
            $scope = $request->query('_inlay_action_scope');
            $record = $request->query('record');
            $records = $request->input('records', []);
            $selection = $request->input('selection');
            if (
                ! is_string($name)
                || ! is_string($action)
                || ! is_string($scope)
                || ! in_array($scope, ['header', 'row', 'bulk'], true)
                || ! is_array($records)
                || ($selection !== null && ! is_array($selection))
            ) {
                throw ValidationException::withMessages([
                    'action' => 'Valid table action metadata is required.',
                ]);
            }
            if ($scope === 'row') {
                $records = is_string($record) || is_int($record) ? [$record] : [];
            }

            $arguments = $request->except([
                '_inlay_action',
                '_inlay_action_form',
                '_inlay_action_scope',
                'record',
                'records',
                'selection',
                'table',
            ]);
            $recordKeys = array_values(array_filter($records, fn (mixed $key): bool => is_string($key) || is_int($key)));
            $runner = $container->make(ActionRunner::class);
            if ($request->boolean('_inlay_action_form') && $runner->handlesFormSubRequest($request)) {
                $subRequest = $page->resolveTableLifecycleActionFormRequest(
                    $request,
                    $runner,
                    $name,
                    $action,
                    $scope,
                    $arguments,
                    $selection,
                    $recordKeys,
                );

                return response()->json($subRequest['payload'], $subRequest['status']);
            }
            $result = $request->boolean('_inlay_action_form')
                ? $page->mountTableLifecycleActionForm(
                    $request,
                    $runner,
                    $name,
                    $action,
                    $scope,
                    $arguments,
                    $selection,
                    $recordKeys,
                )
                : $page->runTableLifecycleAction(
                    $request,
                    $runner,
                    $name,
                    $action,
                    $scope,
                    $arguments,
                    $selection,
                    $recordKeys,
                );

            return response()->json($result);
        }

        if ($request->boolean('_inlay_table_options')) {
            $name = $request->query('table');
            $filter = $request->query('filter');
            $constraint = $request->query('constraint', '');
            $values = $request->query('values', []);
            $search = $request->query('search', '');
            if (! is_string($name) || ! is_string($filter) || ! is_string($constraint) || ! is_array($values) || count($values) > 200 || ! is_string($search) || mb_strlen($search) > 200) {
                throw ValidationException::withMessages([
                    'table' => 'Valid bounded table, filter, constraint, search, and values parameters are required.',
                ]);
            }

            $bounded = array_values(array_filter($values, fn (mixed $value): bool => is_string($value) || is_int($value)));

            // Without a constraint the filter owns its own options.
            if ($constraint === '') {
                return response()->json([
                    'options' => $page->resolveTableFilterOptions($request, $name, $filter, $search, $bounded),
                ]);
            }

            return response()->json([
                'options' => $page->resolveTableRelationshipOptions(
                    $request,
                    $name,
                    $filter,
                    $constraint,
                    $search,
                    array_values(array_filter($values, fn (mixed $value): bool => is_string($value) || is_int($value))),
                ),
            ]);
        }

        if ($request->isMethod('patch')) {
            $name = $request->input('table');
            $records = $request->input('records');
            $startPosition = $request->integer('startPosition', 1);
            if (! is_string($name) || ! is_array($records) || $startPosition < 1) {
                throw ValidationException::withMessages([
                    'table' => 'A valid table name and records array are required.',
                ]);
            }

            $version = $request->input('version');
            $page->reorderTableRecords(
                $request,
                $name,
                array_values($records),
                $startPosition,
                is_string($version) ? $version : null,
            );

            if ($request->header('X-Inertia') === 'true') {
                return redirect()->back(303);
            }

            return response()->noContent();
        }

        $tables = $page->resolveTables($request);
        foreach ($tables as $table) {
            $table->defaultReorderUrl($request->url());
            $table->defaultRemoteOptionsUrl($request->url());
            $table->defaultLifecycleActionUrls($request->url());
            $table->defaultExportUrls($request->url());
            $table->defaultEditableColumnUrl($request->url());
            $table->defaultPersonalViewUrl($request->url());
        }

        return Inertia::render($pageClass::component(), [
            ...$page->resolveProps($request),
            'table' => array_values($tables)[0],
            'tables' => $tables,
        ]);
    }
}
