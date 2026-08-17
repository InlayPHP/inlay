import { Head, usePage } from "@inertiajs/react";
import { Form } from "@inlayphp/forms-react";
import type { FormErrors, FormResource } from "@inlayphp/forms-react";
import { ResourcePage } from "@inlayphp/resources-react";
import type {
  ResourceBreadcrumb,
  ResourceSubNavigationItem,
} from "@inlayphp/resources-react";
import InlayPanelLayout from "./inlay-panel-layout";
import type { PanelResource } from "@inlayphp/panels-react";

type PageProps = {
  inlayPanel: PanelResource;
  breadcrumbs?: ResourceBreadcrumb[];
  errors: FormErrors;
  form: FormResource;
  heading: string;
  subheading?: string | null;
  subNavigation?: ResourceSubNavigationItem[];
};

export default function UserForm({
  breadcrumbs = [],
  form,
  heading,
  subheading,
  subNavigation = [],
}: PageProps) {
  const { errors, inlayPanel } = usePage<PageProps>().props;
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
        breadcrumbs={breadcrumbs}
        heading={heading}
        subNavigation={subNavigation}
        subheading={subheading}
      >
        <section className="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 sm:p-8">
          <Form errors={errors} resource={form} theme={theme} />
        </section>
      </ResourcePage>
    </InlayPanelLayout>
  );
}
