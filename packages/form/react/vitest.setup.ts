import '@testing-library/jest-dom/vitest'

document.elementFromPoint = () => document.querySelector('.ProseMirror') ?? document.body
Range.prototype.getBoundingClientRect = () => new DOMRect()
Range.prototype.getClientRects = () => ({ item: () => null, length: 0, [Symbol.iterator]: function* () {} }) as DOMRectList
