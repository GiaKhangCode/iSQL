export function draftsSynced(local: Record<string,{revision?:number}>, remote: Record<string,{revision?:number}>): boolean {
  return Object.entries(local).every(([id,draft])=>Boolean(remote[id]) && (remote[id].revision||0)>=(draft.revision||0));
}
