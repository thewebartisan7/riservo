/**
 * Collaborator color palette for the calendar view (D-059).
 * Each entry provides Tailwind classes for consistent event styling.
 */
export interface CollaboratorColor {
    bg: string;
    hoverBg: string;
    text: string;
    accent: string;
    dot: string;
}

const COLLABORATOR_COLORS: CollaboratorColor[] = [
    { bg: 'bg-blue-50', hoverBg: 'hover:bg-blue-100', text: 'text-blue-700', accent: 'text-blue-500', dot: 'bg-blue-500' },
    { bg: 'bg-pink-50', hoverBg: 'hover:bg-pink-100', text: 'text-pink-700', accent: 'text-pink-500', dot: 'bg-pink-500' },
    { bg: 'bg-indigo-50', hoverBg: 'hover:bg-indigo-100', text: 'text-indigo-700', accent: 'text-indigo-500', dot: 'bg-indigo-500' },
    { bg: 'bg-green-50', hoverBg: 'hover:bg-green-100', text: 'text-green-700', accent: 'text-green-500', dot: 'bg-green-500' },
    { bg: 'bg-amber-50', hoverBg: 'hover:bg-amber-100', text: 'text-amber-700', accent: 'text-amber-500', dot: 'bg-amber-500' },
    { bg: 'bg-purple-50', hoverBg: 'hover:bg-purple-100', text: 'text-purple-700', accent: 'text-purple-500', dot: 'bg-purple-500' },
    { bg: 'bg-teal-50', hoverBg: 'hover:bg-teal-100', text: 'text-teal-700', accent: 'text-teal-500', dot: 'bg-teal-500' },
    { bg: 'bg-rose-50', hoverBg: 'hover:bg-rose-100', text: 'text-rose-700', accent: 'text-rose-500', dot: 'bg-rose-500' },
];

export function getCollaboratorColor(index: number): CollaboratorColor {
    return COLLABORATOR_COLORS[index % COLLABORATOR_COLORS.length];
}

export function getCollaboratorColorMap(collaboratorIds: number[]): Map<number, CollaboratorColor> {
    const map = new Map<number, CollaboratorColor>();
    collaboratorIds.forEach((id, index) => {
        map.set(id, getCollaboratorColor(index));
    });
    return map;
}
