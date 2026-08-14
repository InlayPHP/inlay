import { Head, usePage } from "@inertiajs/react";
import { Form } from "@inlayphp/forms-react";
import type { FormErrors, FormResource } from "@inlayphp/forms-react";
import { ResourcePage } from "@inlayphp/resources-react";
import type {
  ResourceBreadcrumb,
  ResourceSubNavigationItem,
} from "@inlayphp/resources-react";
import InlayPanelLayout from "../../layouts/inlay-panel-layout";

type PageProps = {
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
  const { errors } = usePage<PageProps>().props;

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
          <Form errors={errors} resource={form} />
        </section>
      </ResourcePage>
    </InlayPanelLayout>
  );
}
