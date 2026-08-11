import { PermissionIndexPage } from '@inlayphp/permission-manager-react';
import type { ListPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function PermissionsIndex(props: ListPageProps) {
    return (
        <AdminLayout>
            <PermissionIndexPage {...props} />
        </AdminLayout>
    );
}
