import { AccessAuditPage } from '@inlayphp/permission-manager-react';
import type { AccessAuditPageProps } from '@inlayphp/permission-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function AuditIndex(props: AccessAuditPageProps) {
    return (
        <AdminLayout>
            <AccessAuditPage {...props} />
        </AdminLayout>
    );
}
