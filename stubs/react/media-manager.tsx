import { MediaManagerPage } from "@inlayphp/media-manager-react";
import type { MediaManagerPageProps } from "@inlayphp/media-manager-react";
import InlayPanelLayout from "./inlay-panel-layout";

export default function MediaIndex(props: MediaManagerPageProps) {
  return (
    <InlayPanelLayout>
      <MediaManagerPage {...props} />
    </InlayPanelLayout>
  );
}
