import { Head, useForm } from "@inertiajs/react";
import type { PanelResource } from "@inlayphp/panels-react";
import { customThemeCss, recipeVariables } from "@inlayphp/theme";
import {
  buttonPrimaryClass,
  controlClass,
  labelClass,
} from "@inlayphp/ui-react";
import type { CSSProperties, FormEvent, ReactNode } from "react";

type LoginProps = { inlayPanel: PanelResource };

export default function Login({ inlayPanel }: LoginProps) {
  const form = useForm({ email: "", password: "", remember: false });
  const theme = {
    contract: "inlay.themes.v1" as const,
    name: inlayPanel.themeName ?? inlayPanel.id,
    tokens: inlayPanel.theme,
    darkTokens: inlayPanel.darkTheme ?? {},
  };
  const scope = `login-${inlayPanel.id}`;

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post(`${inlayPanel.path}/login`);
  }

  return (
    <>
      <Head title={`Sign in to ${inlayPanel.brandName ?? "Inlay"}`} />
      <style>{customThemeCss(theme, scope)}</style>
      <main
        className="flex min-h-dvh items-center justify-center bg-(--inlay-background) p-6 font-[family-name:var(--inlay-font-family)] text-(--inlay-foreground)"
        data-inlay-theme-root={scope}
        style={recipeVariables(theme) as CSSProperties}
      >
        <section className="w-full max-w-md rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-6 shadow-(--inlay-shadow) sm:p-8">
          <p className="text-sm font-medium text-(--inlay-accent)">
            {inlayPanel.brandName ?? "Inlay"}
          </p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight">
            Sign in to your panel
          </h1>
          <p className="mt-2 text-sm text-(--inlay-muted)">
            Use your Laravel account to continue.
          </p>

          <form className="mt-8 space-y-5" onSubmit={submit}>
            <Field error={form.errors.email} label="Email address">
              <input
                autoComplete="email"
                autoFocus
                className={controlClass}
                onChange={(event) => form.setData("email", event.target.value)}
                type="email"
                value={form.data.email}
              />
            </Field>
            <Field error={form.errors.password} label="Password">
              <input
                autoComplete="current-password"
                className={controlClass}
                onChange={(event) =>
                  form.setData("password", event.target.value)
                }
                type="password"
                value={form.data.password}
              />
            </Field>
            <label className="flex items-center gap-3 text-sm text-(--inlay-muted)">
              <input
                checked={form.data.remember}
                onChange={(event) =>
                  form.setData("remember", event.target.checked)
                }
                type="checkbox"
              />
              Remember me
            </label>
            <button
              className={`${buttonPrimaryClass} w-full`}
              disabled={form.processing}
              type="submit"
            >
              {form.processing ? "Signing in…" : "Sign in"}
            </button>
          </form>
        </section>
      </main>
    </>
  );
}

function Field({
  children,
  error,
  label,
}: {
  children: ReactNode;
  error?: string;
  label: string;
}) {
  return (
    <label className="block space-y-2">
      <span className={labelClass}>{label}</span>
      {children}
      {error ? (
        <span className="text-sm text-(--inlay-danger)">{error}</span>
      ) : null}
    </label>
  );
}
