import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
const result=await build({stdin:{contents:'export * from "./src/shared/lib/contest/audienceFiles";',resolveDir:process.cwd(),loader:'ts'},bundle:true,write:false,platform:'node',format:'cjs',logLevel:'silent'});
const file=join(tmpdir(),`audience-files-${process.pid}.cjs`);await writeFile(file,result.outputFiles[0].contents);
try{
  const {readAudienceFile:read,createAudienceTemplate:template,audienceTemplateText}=createRequire(import.meta.url)(file);
  const makeFile=async(blob,name)=>new File([await blob.arrayBuffer()],name);
  const blob=template('classes',['C1','C2']);
  assert.equal(await blob.text(), audienceTemplateText('classes',['C1','C2']), 'The displayed example matches the downloaded CSV');
  assert.equal(audienceTemplateText('students'),'student_id\r\n23520001\r\n23520002');
  assert.deepEqual(await read(await makeFile(blob,'classes.csv'),['C1','C2'],'classes'),{classIds:['C1','C2'],studentEmails:[]});
  assert.deepEqual((await read(await makeFile(template('students'),'students.csv'),[],'students')).studentEmails,['23520001','23520002']);
  assert.deepEqual((await read(new File(['00123456'],'students.csv'),[],'students')).studentEmails,['00123456']);
  for(const extension of ['xlsx','xls','txt']) await assert.rejects(()=>read(new File(['data'],`bad.${extension}`),[],'students'),/Choose a CSV/);
  await assert.rejects(()=>read(new File([new Uint8Array(1024*1024+1)],'large.csv'),[],'students'),/smaller/);
  console.log('PASS: CSV templates round-trip, leading zeros, CSV-only validation and size limits.');
}finally{await unlink(file);}
process.exit(0);
