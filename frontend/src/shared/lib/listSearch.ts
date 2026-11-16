/** Match every search term, ignoring Vietnamese accents and extra whitespace. */
export function matchesListSearch(query: string, ...values: unknown[]): boolean {
  const normalize = (value: unknown) => String(value ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/đ/g, "d").replace(/Đ/g, "D").toLocaleLowerCase();
  const text = values.map(normalize).join(" ");
  return normalize(query).trim().split(/\s+/).every(term => text.includes(term));
}
