import { Head, usePage } from '@inertiajs/react';
import { AccountSettingsPage as AccountSettings } from '@inlayphp/panels-react';
import type { AccountSettingsPageProps } from '@inlayphp/panels-react';
import AdminLayout from '@/layouts/admin-layout';

type PageProps = AccountSettingsPageProps & {
    flash?: { success?: string | null };
};

export default function AccountSettingsPage(props: PageProps) {
    const { errors, flash } = usePage<PageProps>().props;

    return (
        <AdminLayout>
            <Head title="Account settings" />
            <AccountSettings {...props} errors={errors} flash={flash} />
        </AdminLayout>
    );
}
