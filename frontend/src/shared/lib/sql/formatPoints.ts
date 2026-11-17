/** Keep calculation precision; round only the visible score. */
export function formatPoints(value: number | null | undefined) {
  return value == null ? "—" : String(Number(value.toFixed(2)));
}
