import { UserAccessFormPage } from '@inlayphp/permission-manager-react';
import type { UserAccessFormPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function UserAccessForm(props: UserAccessFormPageProps) {
    return (
        <AdminLayout>
            <UserAccessFormPage {...props} />
        </AdminLayout>
    );
}
