import type { PanelIconProps, PanelIconRegistry } from '@inlayphp/panels-react';
import {
    Home,
    KeyRound,
    Layers3,
    Shield,
    ShieldCheck,
    Images,
    UserCheck,
    UserCircle,
    Users,
} from 'lucide-react';

function icon(Component: typeof Home) {
    return function AdminIcon({ className }: PanelIconProps) {
        return (
            <Component
                aria-hidden="true"
                className={className}
                strokeWidth={1.8}
            />
        );
    };
}

export const adminIcons: PanelIconRegistry = {
    brand: icon(Layers3),
    home: icon(Home),
    key: icon(KeyRound),
    photo: icon(Images),
    images: icon(Images),
    shield: icon(Shield),
    'shield-check': icon(ShieldCheck),
    users: icon(Users),
    'user-check': icon(UserCheck),
    'user-circle': icon(UserCircle),
};
