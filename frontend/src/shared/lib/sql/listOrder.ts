import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";

/** Stable newest-first lists. Unknown historical dates stay after dated rows. */
export function newestFirst<T extends {id:string}>(rows:readonly T[], timestamp:(row:T)=>string|null|undefined):T[] {
  const time=(row:T)=>{const value=timestamp(row);if(!value)return Number.NEGATIVE_INFINITY;const date=parseServerDateTime(value).getTime();return Number.isFinite(date)?date:Number.NEGATIVE_INFINITY;};
  return [...rows].sort((a,b)=>{
    const left=time(a),right=time(b);
    return left!==right ? (left<right?1:-1) : b.id.localeCompare(a.id,undefined,{numeric:true});
  });
}
