import { RoleFormPage } from '@inlayphp/permission-manager-react';
import type { FormPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function RoleForm(props: FormPageProps) {
    return (
        <AdminLayout>
            <RoleFormPage {...props} />
        </AdminLayout>
    );
}
