import { UserAccessIndexPage } from '@inlayphp/permission-manager-react';
import type { UserAccessListPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function UserAccessIndex(props: UserAccessListPageProps) {
    return (
        <AdminLayout>
            <UserAccessIndexPage {...props} />
        </AdminLayout>
    );
}
