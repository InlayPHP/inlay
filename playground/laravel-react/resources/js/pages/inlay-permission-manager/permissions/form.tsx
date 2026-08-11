import { PermissionFormPage } from '@inlayphp/permission-manager-react';
import type { FormPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function PermissionForm(props: FormPageProps) {
    return (
        <AdminLayout>
            <PermissionFormPage {...props} />
        </AdminLayout>
    );
}
