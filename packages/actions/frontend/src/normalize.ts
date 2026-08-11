import type { ActionModalResource, ActionResource, NormalizedAction, NormalizedActionModal } from './types'
import { snapshotWireRecord } from './wire'

const supportedAlignments = new Set(['start', 'center'])
const supportedTriggerStyles = new Set(['button', 'link', 'icon-button', 'badge'])

export function normalizeAction(action: ActionResource): NormalizedAction {
  const requiresConfirmation = action.requiresConfirmation || action.modal != null || action.form != null
  const triggerStyle = supportedTriggerStyles.has(action.triggerStyle ?? '')
    ? action.triggerStyle as NonNullable<ActionResource['triggerStyle']>
    : 'button'

  return Object.freeze({
    ...action,
    triggerStyle,
    iconPosition: action.iconPosition === 'after' ? 'after' : 'before',
    size: action.size ?? 'medium',
    badgeColor: nonEmpty(action.badgeColor) ?? 'default',
    keyBindings: Array.isArray(action.keyBindings) ? action.keyBindings.filter(binding => typeof binding === 'string') : [],
    requiresConfirmation,
    modal: requiresConfirmation ? normalizeActionModal(action.modal, action, action.instanceKey ?? action.name) : null,
    data: snapshotWireRecord(action.data ?? {}, 'action.data'),
    arguments: snapshotWireRecord(action.arguments ?? {}, 'action.arguments'),
    bulk: action.bulk ?? false,
  })
}

export function normalizeActionModal(modal: ActionModalResource | null | undefined, action: Pick<ActionResource, 'name' | 'label' | 'modalHeading'>, parentIdentity = action.name): NormalizedActionModal {
  const heading = nonEmpty(modal?.heading) ?? nonEmpty(action.modalHeading) ?? `Confirm ${action.label}`
  const submitLabel = nonEmpty(modal?.submitLabel) ?? action.label
  const cancelLabel = nonEmpty(modal?.cancelLabel) ?? 'Cancel'
  return Object.freeze({
    heading,
    description: nonEmpty(modal?.description),
    submitLabel,
    cancelLabel,
    icon: nonEmpty(modal?.icon),
    iconColor: nonEmpty(modal?.iconColor),
    width: nonEmpty(modal?.width) ?? 'md',
    alignment: supportedAlignments.has(modal?.alignment ?? '') ? modal!.alignment as 'start' | 'center' : 'start',
    closeOnBackdrop: modal?.closeOnBackdrop ?? true,
    closeOnEscape: modal?.closeOnEscape ?? true,
    autofocus: modal?.autofocus ?? true,
    slideOver: modal?.slideOver ?? false,
    stickyHeader: modal?.stickyHeader ?? false,
    stickyFooter: modal?.stickyFooter ?? false,
    submitAction: modal?.submitAction === false
      ? null
      : normalizeFooterAction(modal?.submitAction, 'submit', submitLabel, 'primary', parentIdentity),
    cancelAction: modal?.cancelAction === false
      ? null
      : normalizeFooterAction(modal?.cancelAction, 'cancel', cancelLabel, 'default', parentIdentity),
    extraFooterActions: Object.freeze((modal?.extraFooterActions ?? []).map((footerAction, index) =>
      normalizeFooterAction(footerAction, `extra-${index + 1}`, `Action ${index + 1}`, 'default', parentIdentity),
    )),
    dynamic: modal?.dynamic ?? false,
    endpoint: nonEmpty(modal?.endpoint),
  })
}

function normalizeFooterAction(action: ActionResource | null | undefined, name: string, label: string, color: string, parentIdentity: string): NormalizedAction {
  const instanceKey = action?.instanceKey ?? `${parentIdentity}.${name}`
  if (action?.modalFooterMode === 'action') return normalizeAction({ ...action, instanceKey })

  return normalizeAction({
    name,
    label,
    url: null,
    method: 'post',
    color,
    icon: null,
    modalHeading: null,
    ...action,
    instanceKey,
    // A footer submit variant always belongs to the already-open parent modal.
    requiresConfirmation: false,
    modal: null,
    form: null,
    lifecycle: false,
    modalFooterMode: 'submit',
  })
}

function nonEmpty(value: string | null | undefined): string | null {
  if (typeof value !== 'string') return null
  const normalized = value.trim()
  return normalized === '' ? null : normalized
}
