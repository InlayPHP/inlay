<?php

declare(strict_types=1);

namespace Inlay\Forms\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Scaffold a community schema-view package.
 *
 * A schema view needs a PHP factory and matching React and Vue renderers that
 * register under the same view name. Writing them by hand is where the three
 * drift apart, so the generator writes all of them together.
 */
final class MakeSchemaPackageCommand extends Command
{
    protected $signature = 'make:inlay-schema-package
        {vendor : Vendor name, for example acme}
        {name : View name, for example order-summary}
        {--path= : Directory to write the package into, relative to the project root}
        {--force : Overwrite existing files}';

    protected $description = 'Scaffold a community Inlay schema view package';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $vendor = $this->slug((string) $this->argument('vendor'));
        $name = $this->slug((string) $this->argument('name'));

        if ($vendor === null || $name === null) {
            $this->components->error('The vendor and view name must be lowercase words separated by hyphens.');

            return self::FAILURE;
        }

        $relative = $this->packagePath((string) ($this->option('path') ?: 'packages/'.$vendor.'-'.$name));
        if ($relative === null) {
            $this->components->error('The package path must be a safe relative path inside the project.');

            return self::FAILURE;
        }

        $directory = $this->laravel->basePath($relative);
        $view = $vendor.'/'.$name;
        $studly = Str::studly($name);
        $namespace = Str::studly($vendor).'\\Inlay'.$studly;
        $composerName = $vendor.'/inlay-'.$name;
        $packageName = '@'.$vendor.'/inlay-'.$name;

        $files = [
            '.gitignore' => "/dist/\n/vendor/\n/node_modules/\n",
            'README.md' => $this->readmeSource($composerName, $packageName, $namespace, $studly, $view),
            'composer.json' => $this->composerSource($composerName, $namespace),
            'package.json' => $this->packageSource($packageName),
            'tsconfig.json' => $this->tsconfigSource(),
            'vitest.config.ts' => $this->vitestConfigSource(),
            'src/'.$studly.'.php' => $this->phpSource($namespace, $studly, $view),
            'src/react.tsx' => $this->reactSource($studly, $view, $packageName),
            'src/vue.ts' => $this->vueSource($studly, $view, $packageName),
            'tests/'.$studly.'Test.php' => $this->phpTestSource($namespace, $studly),
            'tests/registry.test.ts' => $this->registryTestSource($studly, $view, $packageName),
        ];

        foreach ($files as $path => $source) {
            if ($this->files->exists($directory.'/'.$path) && ! $this->option('force')) {
                $this->components->error("File already exists: {$relative}/{$path}");

                return self::FAILURE;
            }
        }

        foreach ($files as $path => $source) {
            $this->files->ensureDirectoryExists(dirname($directory.'/'.$path));
            $this->files->put($directory.'/'.$path, $source);
            $this->components->info("Created {$relative}/{$path}");
        }

        $this->newLine();
        $this->components->info("Register the renderers under the view name [{$view}] in both React and Vue.");

        return self::SUCCESS;
    }

    private function slug(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $value) === 1 ? $value : null;
    }

    private function packagePath(string $value): ?string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || str_starts_with($value, '/') || preg_match('/^[A-Za-z]:\//', $value) === 1) {
            return null;
        }

        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ($segment === '' || in_array($segment, ['.', '..'], true) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment) !== 1) {
                return null;
            }
        }

        return implode('/', $segments);
    }

    private function composerSource(string $composerName, string $namespace): string
    {
        $escaped = str_replace('\\', '\\\\', $namespace);

        return <<<JSON
        {
            "name": "{$composerName}",
            "description": "Community schema view for Inlay.",
            "type": "library",
            "license": "MIT",
            "require": {
                "php": "^8.3",
                "inlayphp/schemas": "^0.3 || dev-main"
            },
            "require-dev": {
                "pestphp/pest": "^3.8 || ^4.0 || ^5.0"
            },
            "autoload": {
                "psr-4": {
                    "{$escaped}\\\\": "src/"
                }
            },
            "autoload-dev": {
                "psr-4": {
                    "{$escaped}Tests\\\\": "tests/"
                }
            },
            "scripts": {
                "test": "pest"
            },
            "minimum-stability": "dev",
            "prefer-stable": true
        }

        JSON;
    }

    private function packageSource(string $packageName): string
    {
        return <<<JSON
        {
            "name": "{$packageName}",
            "version": "0.1.0",
            "description": "Community schema view for Inlay.",
            "license": "MIT",
            "type": "module",
            "sideEffects": false,
            "exports": {
                "./react": {
                    "types": "./dist/react.d.ts",
                    "import": "./dist/react.js"
                },
                "./vue": {
                    "types": "./dist/vue.d.ts",
                    "import": "./dist/vue.js"
                }
            },
            "files": [
                "dist",
                "src"
            ],
            "scripts": {
                "build": "tsup src/react.tsx src/vue.ts --format esm --dts --clean --external react --external vue --external @inlayphp/core --external @inlayphp/forms-react --external @inlayphp/forms-vue",
                "test": "vitest --config vitest.config.ts",
                "typecheck": "tsc --noEmit"
            },
            "peerDependencies": {
                "@inlayphp/core": "^0.2.0",
                "@inlayphp/forms-react": "^0.1.0",
                "@inlayphp/forms-vue": "^0.1.0",
                "react": "^19.0.0",
                "vue": "^3.4.0"
            },
            "devDependencies": {
                "@inlayphp/core": "^0.2.0",
                "@inlayphp/forms-react": "^0.1.0",
                "@inlayphp/forms-vue": "^0.1.0",
                "@types/react": "^19.0.0",
                "react": "^19.0.0",
                "tsup": "^8.3.5",
                "typescript": "^5.7.2",
                "vitest": "^3.0.5",
                "vue": "^3.5.13"
            }
        }

        JSON;
    }

    private function tsconfigSource(): string
    {
        return <<<'JSON'
        {
            "compilerOptions": {
                "target": "ES2022",
                "module": "ESNext",
                "moduleResolution": "Bundler",
                "jsx": "react-jsx",
                "strict": true,
                "declaration": true,
                "esModuleInterop": true,
                "skipLibCheck": true,
                "types": ["vitest/globals"]
            },
            "include": ["src", "tests"]
        }

        JSON;
    }

    private function vitestConfigSource(): string
    {
        return <<<'TS'
        import { defineConfig } from 'vitest/config'

        export default defineConfig({
          test: {
            environment: 'node',
            include: ['tests/**/*.test.ts'],
          },
        })

        TS;
    }

    private function phpSource(string $namespace, string $class, string $view): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Closure;
        use Inlay\\Schemas\\Components\\View;

        final class {$class}
        {
            public const VIEW = '{$view}';

            /** @param array<string, mixed>|Closure \$data */
            public static function make(array|Closure \$data = []): View
            {
                return View::make(self::VIEW)->viewData(\$data);
            }
        }

        PHP;
    }

    private function reactSource(string $class, string $view, string $packageName): string
    {
        $handle = lcfirst($class);

        return <<<TSX
        import type { FormRendererRegistryTypes, SchemaComponentRenderer } from '@inlayphp/forms-react'
        import type { RendererRegistrySet } from '@inlayphp/core'

        export const {$handle}View = '{$view}'

        export const {$class}: SchemaComponentRenderer = ({ component, renderSchema }) => (
          <article aria-label={component.label} data-view={{$handle}View}>
            {renderSchema()}
          </article>
        )

        export function register{$class}(
          registries: RendererRegistrySet<FormRendererRegistryTypes>,
        ) {
          return registries.schema.register({$handle}View, {$class}, {
            owner: '{$packageName}',
          })
        }

        TSX;
    }

    private function vueSource(string $class, string $view, string $packageName): string
    {
        $handle = lcfirst($class);
        $component = Str::studly(str_replace('/', '-', $view));

        return <<<TS
        import { defineComponent, h } from 'vue'
        import type { PropType } from 'vue'
        import type { RendererRegistrySet } from '@inlayphp/core'
        import type {
          FormComponent,
          FormNestedSchemaOptions,
          FormRendererRegistryTypes,
        } from '@inlayphp/forms-vue'

        export const {$handle}View = '{$view}'

        export const {$class} = defineComponent({
          name: '{$component}',
          props: {
            component: { type: Object as PropType<FormComponent>, required: true },
            renderSchema: {
              type: Function as PropType<(options?: FormNestedSchemaOptions) => unknown>,
              required: true,
            },
          },
          setup: (props) => () => h('article', {
            'aria-label': props.component.label,
            'data-view': {$handle}View,
          }, [props.renderSchema() as any]),
        })

        export function register{$class}(
          registries: RendererRegistrySet<FormRendererRegistryTypes>,
        ) {
          return registries.schema.register({$handle}View, {$class}, {
            owner: '{$packageName}',
          })
        }

        TS;
    }

    private function phpTestSource(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        use {$namespace}\\{$class};
        use Inlay\\Schemas\\Components\\Text;

        it('publishes a stable wire-safe schema view contract', function (): void {
            \$payload = json_decode(json_encode(
                {$class}::make(['heading' => 'Example'])
                    ->schema([Text::make('Nested schema content')]),
                JSON_THROW_ON_ERROR,
            ), true, flags: JSON_THROW_ON_ERROR);

            expect(\$payload)
                ->type->toBe('view')
                ->rendererCategory->toBe('schema')
                ->view->toBe({$class}::VIEW)
                ->data->toBe(['heading' => 'Example'])
                ->and(\$payload['schema'][0]['content'])->toBe('Nested schema content');
        });

        PHP;
    }

    private function registryTestSource(string $class, string $view, string $packageName): string
    {
        return <<<TS
        import { describe, expect, it } from 'vitest'
        import { createRendererRegistries } from '@inlayphp/core'
        import type { FormRendererRegistryTypes as ReactTypes } from '@inlayphp/forms-react'
        import type { FormRendererRegistryTypes as VueTypes } from '@inlayphp/forms-vue'
        import {
          {$class} as React{$class},
          register{$class} as registerReact,
        } from '../src/react'
        import {
          {$class} as Vue{$class},
          register{$class} as registerVue,
        } from '../src/vue'

        describe('community schema view', () => {
          it('registers the same package-owned view for React and Vue', () => {
            const react = createRendererRegistries<ReactTypes>()
            const vue = createRendererRegistries<VueTypes>()

            registerReact(react)
            registerVue(vue)

            expect(react.schema.get('{$view}')).toBe(React{$class})
            expect(vue.schema.get('{$view}')).toBe(Vue{$class})
            expect(react.schema.registration('{$view}')?.owner)
              .toBe('{$packageName}')
            expect(vue.schema.registration('{$view}')?.owner)
              .toBe('{$packageName}')
            expect(() => registerReact(react)).toThrow(/already/)
          })
        })

        TS;
    }

    private function readmeSource(
        string $composerName,
        string $packageName,
        string $namespace,
        string $class,
        string $view,
    ): string {
        return <<<MD
        # {$class}

        A renderer-neutral Inlay schema view with matching React and Vue adapters.

        Contract identity: `{$view}`

        ## Install

        ```bash
        composer require {$composerName}
        npm install {$packageName}
        ```

        ## PHP

        ```php
        use {$namespace}\\{$class};

        {$class}::make(['heading' => 'Example'])
            ->schema([
                // Any nested Inlay schema components.
            ]);
        ```

        Data may also be supplied as a closure with Schema utility injection.
        The resolved data must remain wire-safe.

        ## React

        ```tsx
        import { createRendererRegistries } from '@inlayphp/core'
        import type { FormRendererRegistryTypes } from '@inlayphp/forms-react'
        import { register{$class} } from '{$packageName}/react'

        const registries = createRendererRegistries<FormRendererRegistryTypes>()
        register{$class}(registries)
        ```

        ## Vue

        ```ts
        import { createRendererRegistries } from '@inlayphp/core'
        import type { FormRendererRegistryTypes } from '@inlayphp/forms-vue'
        import { register{$class} } from '{$packageName}/vue'

        const registries = createRendererRegistries<FormRendererRegistryTypes>()
        register{$class}(registries)
        ```

        Pass `registries` to the Inlay Form or Infolist root. Register adapters
        once during application/plugin bootstrap, not during page rendering.

        ## Verify

        ```bash
        composer install
        composer test
        npm install
        npm run typecheck
        npm test -- --run
        npm run build
        ```

        The included tests verify the PHP wire contract, nested schema rendering,
        matching React/Vue identities, and explicit registry ownership.

        MD;
    }
}
