import { MediaManagerPage } from '@inlayphp/media-manager-react';
import type { MediaManagerPageProps } from '@inlayphp/media-manager-react';
import AdminLayout from '@/layouts/admin-layout';

export default function MediaIndex(props: MediaManagerPageProps) {
    return (
        <AdminLayout>
            <MediaManagerPage {...props} />
        </AdminLayout>
    );
}
