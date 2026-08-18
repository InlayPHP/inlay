import { router, usePage } from "@inertiajs/react";
import { Panel } from "@inlayphp/panels-react";
import type { PanelResource } from "@inlayphp/panels-react";
import type { PropsWithChildren } from "react";

type PanelPageProps = {
  auth: { user: { name: string; email: string } };
  inlayPanel: PanelResource;
  inlayPage?: Record<string, unknown>;
  page?: Record<string, unknown>;
  resource?: Record<string, unknown>;
};

export default function InlayPanelLayout({ children }: PropsWithChildren) {
  const { auth, inlayPanel, inlayPage, page, resource } =
    usePage<PanelPageProps>().props;

  return (
    <Panel
      conditionValues={{ page: inlayPage ?? page, resource }}
      onNavigate={(href) => router.visit(href)}
      resource={inlayPanel}
      slots={{
        headerStart: (
          <nav aria-label="Breadcrumb" className="hidden items-center gap-2 text-xs text-(--inlay-muted) lg:flex" data-slot="topbar-breadcrumb">
            <span>Workspace</span>
            <span aria-hidden="true">/</span>
            <strong className="font-semibold text-(--inlay-text)">Administration</strong>
          </nav>
        ),
        headerEnd: (
          <div className="flex items-center gap-3">
            <div className="hidden text-right sm:block">
              <p className="text-sm font-medium">{auth.user.name}</p>
              <p className="text-xs text-(--inlay-muted)">{auth.user.email}</p>
            </div>
            <button
              className="inline-flex min-h-(--inlay-button-height) items-center rounded-(--inlay-radius) border border-(--inlay-control-border) px-3 text-sm font-medium hover:bg-(--inlay-hover)"
              onClick={() => router.post(`${inlayPanel.path}/logout`)}
              type="button"
            >
              Sign out
            </button>
          </div>
        ),
      }}
    >
      {children}
    </Panel>
  );
}
