import { Head, usePage } from "@inertiajs/react";
import { AccountSettingsPage as AccountSettings } from "@inlayphp/panels-react";
import type { AccountSettingsPageProps } from "@inlayphp/panels-react";
import InlayPanelLayout from "./inlay-panel-layout";

type PageProps = AccountSettingsPageProps & {
  flash?: { success?: string | null };
};

export default function AccountSettingsPage(props: PageProps) {
  const { errors, flash } = usePage<PageProps>().props;

  return (
    <InlayPanelLayout>
      <Head title="Account settings" />
      <AccountSettings {...props} errors={errors} flash={flash} />
    </InlayPanelLayout>
  );
}
