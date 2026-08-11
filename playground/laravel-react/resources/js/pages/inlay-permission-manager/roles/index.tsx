import { RoleIndexPage } from '@inlayphp/permission-manager-react';
import type { ListPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function RolesIndex(props: ListPageProps) {
    return (
        <AdminLayout>
            <RoleIndexPage {...props} />
        </AdminLayout>
    );
}
