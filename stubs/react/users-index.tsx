import { Head, Link, usePage } from "@inertiajs/react";
import { ResourcePage } from "@inlayphp/resources-react";
import type {
  ResourceBreadcrumb,
  ResourceSubNavigationItem,
  ResourceTabsResource,
} from "@inlayphp/resources-react";
import { Table } from "@inlayphp/tables-react";
import type { PanelResource } from "@inlayphp/panels-react";
import type { TableResource } from "@inlayphp/tables-react";
import InlayPanelLayout from "./inlay-panel-layout";

type PageProps = {
  inlayPanel: PanelResource;
  breadcrumbs?: ResourceBreadcrumb[];
  heading: string;
  subheading?: string | null;
  subNavigation?: ResourceSubNavigationItem[];
  table: TableResource;
  tabs?: ResourceTabsResource | null;
};

export default function UsersIndex({
  breadcrumbs = [],
  heading,
  subheading,
  subNavigation = [],
  table,
  tabs = null,
}: PageProps) {
  const { inlayPanel } = usePage<PageProps>().props;
  const theme = {
    contract: "inlay.themes.v1" as const,
    name: inlayPanel.themeName ?? inlayPanel.id,
    tokens: inlayPanel.theme,
    darkTokens: inlayPanel.darkTheme ?? {},
  };

  return (
    <InlayPanelLayout>
      <Head title={heading} />
      <ResourcePage
        actions={
          <Link
            className="inline-flex min-h-(--inlay-button-height) items-center rounded-(--inlay-radius) bg-(--inlay-accent) px-4 text-sm font-semibold text-(--inlay-accent-foreground)"
            href="/{{ panel }}/users/create"
          >
            Create user
          </Link>
        }
        breadcrumbs={breadcrumbs}
        heading={heading}
        subNavigation={subNavigation}
        subheading={subheading ?? "Create and maintain panel accounts."}
        tabs={tabs}
      >
        <section className="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 sm:p-6">
          <Table resource={table} theme={theme} />
        </section>
      </ResourcePage>
    </InlayPanelLayout>
  );
}
