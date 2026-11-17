import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
const result=await build({stdin:{contents:'export {parseAudienceCsv} from "./src/shared/lib/contest/audienceCsv";',resolveDir:process.cwd(),loader:'ts'},bundle:true,write:false,platform:'node',format:'cjs'});
const file=join(tmpdir(),`audience-csv-${process.pid}.cjs`);await writeFile(file,result.outputFiles[0].contents);
try{
  const {parseAudienceCsv:parse}=createRequire(import.meta.url)(file);
  assert.deepEqual(parse('\uFEFFclass_id,student_id,email,name\r\nC1,23520001,23520001@school.edu,"Name, quoted"\r\nC1,23520001,,Other',['C1']),{classIds:['C1'],studentEmails:['23520001','23520001@school.edu']});
  assert.deepEqual(parse('MSSV;Email\n23520001;Student@School.edu',[]),{classIds:[],studentEmails:['23520001','student@school.edu']});
  assert.deepEqual(parse('C1\n23520001\nstudent@school.edu',['C1']),{classIds:['C1'],studentEmails:['23520001','student@school.edu']});
  assert.deepEqual(parse('12345678\n12345679',['12345678','12345679'],'classes'),{classIds:['12345678','12345679'],studentEmails:[]});
  assert.deepEqual(parse('12345678\nstudent@school.edu',['12345678'],'students'),{classIds:[],studentEmails:['12345678','student@school.edu']});
  assert.throws(()=>parse('unknown-class',['C1'],'classes'),/unknown-class/);
  assert.throws(()=>parse('class_id\nold-class',['C1']),/old-class/);
  assert.throws(()=>parse('student_id\ninvalid',[]),/Dòng 2/);
  assert.throws(()=>parse('"open',[]),/unclosed/);
  assert.throws(()=>parse('',[]),/empty/);
  assert.throws(()=>parse(Array(5002).fill('23520001').join('\n'),[]),/5000/);
  console.log('PASS: CSV BOM, quoted fields, mixed audience columns, separators, deduplication, term-restricted classes, malformed and oversized lists.');
}finally{await unlink(file);}
process.exit(0);
