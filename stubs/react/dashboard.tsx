import { Head, Link } from "@inertiajs/react";
import InlayPanelLayout from "./inlay-panel-layout";

const destinations = [
  ["Users", "Create and manage panel accounts.", "/{{ panel }}/users"],
  [
    "Account settings",
    "Update your profile and password.",
    "/{{ panel }}/settings/account",
  ],
];

export default function Dashboard() {
  return (
    <InlayPanelLayout>
      <Head title="Dashboard" />
      <div className="mx-auto w-full max-w-[1600px] space-y-7">
        <div>
          <p className="font-medium text-(--inlay-accent)">Administration</p>
          <h1 className="mt-2 text-3xl font-semibold tracking-tight">
            Dashboard
          </h1>
          <p className="mt-2 text-sm text-(--inlay-muted)">
            Manage the application from one PHP-configured panel.
          </p>
        </div>
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {destinations.map(([title, description, href]) => (
            <Link
              className="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 transition hover:bg-(--inlay-hover)"
              href={href}
              key={title}
            >
              <h2 className="font-semibold">{title}</h2>
              <p className="mt-2 text-sm leading-6 text-(--inlay-muted)">
                {description}
              </p>
            </Link>
          ))}
        </section>
      </div>
    </InlayPanelLayout>
  );
}
