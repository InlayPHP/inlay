import { Head, usePage } from "@inertiajs/react";
import { AccountSettingsPage as AccountSettings } from "@inlayphp/panels-react";
import type { AccountSettingsPageProps, PanelResource } from "@inlayphp/panels-react";
import InlayPanelLayout from "./inlay-panel-layout";

type PageProps = AccountSettingsPageProps & {
  inlayPanel: PanelResource;
  flash?: { success?: string | null };
};

export default function AccountSettingsPage(props: PageProps) {
  const { errors, flash, inlayPanel } = usePage<PageProps>().props;
  const theme = props.theme ?? {
    contract: "inlay.themes.v1" as const,
    name: inlayPanel.themeName ?? inlayPanel.id,
    tokens: inlayPanel.theme,
    darkTokens: inlayPanel.darkTheme ?? {},
  };

  return (
    <InlayPanelLayout>
      <Head title="Account settings" />
      <AccountSettings {...props} errors={errors} flash={flash} theme={theme} />
    </InlayPanelLayout>
  );
}
