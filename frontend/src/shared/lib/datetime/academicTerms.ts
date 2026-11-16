export function currentAcademicTerm(date = new Date()): string {
  const parts=new Intl.DateTimeFormat("en-US",{timeZone:"Asia/Ho_Chi_Minh",year:"numeric",month:"numeric"}).formatToParts(date);
  const year=Number(parts.find(part=>part.type==="year")!.value),month=Number(parts.find(part=>part.type==="month")!.value);
  const start=month>=8?year:year-1;
  return `HK${month>=2 && month<=7?2:1}_${start}-${start+1}`;
}
export function normalizeAcademicTerm(value:string):string {
  const modern=/^HK\s*([12])[\s_·-]*(\d{4})\s*[-–]\s*(\d{4})$/i.exec(value.trim());
  if(modern && Number(modern[3])===Number(modern[2])+1)return `HK${modern[1]}_${modern[2]}-${modern[3]}`;
  const legacy=/^Semester\s+([12]),\s*(\d{4})$/i.exec(value.trim());
  return legacy?`HK${legacy[1]}_${legacy[2]}-${Number(legacy[2])+1}`:value;
}
export const formatAcademicTerm=(value:string)=>normalizeAcademicTerm(value).replace(/^HK([12])_(\d{4})-(\d{4})$/,"HK$1 · $2–$3");
export function academicTerms(selected = ""): string[] {
  const year = Number(currentAcademicTerm().slice(4,8));
  const terms = Array.from({ length: 7 }, (_, index) => year - 2 + index)
    .flatMap((value) => [1, 2].map((semester) => `HK${semester}_${value}-${value+1}`));
  return selected && !terms.includes(selected) ? [selected, ...terms] : terms;
}
