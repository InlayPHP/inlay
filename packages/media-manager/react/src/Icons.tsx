import type { SVGProps } from 'react'

type IconProps = SVGProps<SVGSVGElement>
const base = { fill: 'none', height: 18, viewBox: '0 0 24 24', width: 18, stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const }
export const GridIcon = (props: IconProps) => <svg {...base} {...props}><rect height="7" rx="1" width="7" x="3" y="3" /><rect height="7" rx="1" width="7" x="14" y="3" /><rect height="7" rx="1" width="7" x="3" y="14" /><rect height="7" rx="1" width="7" x="14" y="14" /></svg>
export const ListIcon = (props: IconProps) => <svg {...base} {...props}><path d="M8 6h13M8 12h13M8 18h13" /><path d="M3 6h.01M3 12h.01M3 18h.01" /></svg>
export const FolderIcon = (props: IconProps) => <svg {...base} {...props}><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2h8.5A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z" /></svg>
export const UploadIcon = (props: IconProps) => <svg {...base} {...props}><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5" /><path d="M5 14v5h14v-5" /></svg>
export const TrashIcon = (props: IconProps) => <svg {...base} {...props}><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5" /></svg>
export const FileIcon = (props: IconProps) => <svg {...base} {...props}><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v5h4" /></svg>
export const CloseIcon = (props: IconProps) => <svg {...base} {...props}><path d="m6 6 12 12M18 6 6 18" /></svg>
export const CheckIcon = (props: IconProps) => <svg {...base} {...props}><path d="m5 12 4 4L19 6" /></svg>
