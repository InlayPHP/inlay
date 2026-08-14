import { Head, Link } from "@inertiajs/react";
import { ResourcePage } from "@inlayphp/resources-react";
import type {
  ResourceBreadcrumb,
  ResourceSubNavigationItem,
  ResourceTabsResource,
} from "@inlayphp/resources-react";
import { Table } from "@inlayphp/tables-react";
import type { TableResource } from "@inlayphp/tables-react";
import InlayPanelLayout from "../../layouts/inlay-panel-layout";

type PageProps = {
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
          <Table resource={table} />
        </section>
      </ResourcePage>
    </InlayPanelLayout>
  );
}
