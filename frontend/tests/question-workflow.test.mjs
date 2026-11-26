import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost',pretendToBeVisual:true});
for(const name of ['window','document','HTMLElement','Element','Node','localStorage','MutationObserver'])globalThis[name]=dom.window[name];
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
HTMLElement.prototype.scrollTo=()=>{};
window.scrollTo=()=>{};
window.matchMedia=()=>({matches:true,addEventListener(){},removeEventListener(){}});
const table=note=>({name:'SANPHAM',columns:[{name:'MASP',type:'char(4)',constraints:'NOT NULL'},...(note?[{name:'GHICHU',type:`varchar(${note})`,constraints:'NULL'}]:[])],primaryKeys:['MASP'],foreignKeys:[],constraints:[]});
const problem={id:'question-workflow',number:'1',title:'Sales',description:'Shared context',topic:'SQL',difficulty:'Easy',progress:'Not started',tables:[],draft:'',schema:'CREATE TABLE SANPHAM(MASP CHAR(4) PRIMARY KEY);',seedData:"INSERT INTO SANPHAM VALUES ('SP01');",workspaceParts:['DDL','DML','SQL','TRIGGER'],
  triggerChecks:[{id:'trigger-1',name:'secret-trigger.sql',description:'Create the product trigger',startFrom:'provided',gradingMode:'exact'},{id:'trigger-2',name:'secret-trigger2.sql',description:'Create a second trigger',startFrom:'provided',gradingMode:'exact'}],
  ddlChecks:[{id:'add',name:'secret-add.sql',description:'Add note',startFrom:'provided'},{id:'resize',name:'secret-resize.sql',description:'Resize note',startFrom:'provided',hasSetup:true},{id:'drop',name:'secret-drop.sql',description:'Drop note',startFrom:'provided',hasSetup:true}],
  dmlChecks:[{id:'price',name:'secret-price.sql',description:'Raise the price',startFrom:'provided',hasSetup:true}],sqlChecks:[{id:'select',name:'secret-select.sql',description:'List products',timeLimitMs:5000,hasSetup:true}]};
let history=[],preparations=[],submissions=[],lastRun,finalInput;
globalThis.__questionProblem=problem;
globalThis.__questionApi={getCurrentDatabase:async()=>({tables:[]}),submitWorkspace:async(_id,input)=>{finalInput=input;return {status:'Accepted',message:'Saved',submissionsUsed:1,maxScore:10,score:10,scoreVisible:true};},getDraft:()=>'',saveDraft(){},getExamSessionOrExam:async()=>undefined,getPreferences:async()=>({favorites:[]}),getSubmissions:async()=>structuredClone(history),
  getQuestionDatabase:async(_id,input)=>{preparations.push(input);return {schemaTables:[table(20)],tables:[{name:'SANPHAM',columns:['MASP','GHICHU'],rows:[['SP01',null]]}]};},
  runWorkspace:async(_id,input)=>{lastRun=input;return input.part==='TRIGGER'?{status:'Success',message:'Trigger created successfully.',triggers:[{name:'trg_product',table:'SANPHAM',isDisabled:false}],tables:[{name:'SANPHAM',columns:['MASP'],rows:[['SP01']]}]}:input.part==='DDL'?{status:'Success',message:'DDL executed successfully.',schemaTables:[table(20),{...table(),name:'CTHD'}],schemaChanges:[{name:'SANPHAM',change:'modified',before:table(),table:table(20)}]}:{status:'Success',message:'DML executed successfully.',tables:[{name:'SANPHAM1',columns:['GIA'],rows:[[105]]},{name:'KHACHHANG',columns:['MAKH'],rows:[['KH01']]}],dataChanges:[{name:'SANPHAM1',change:'modified',before:{name:'SANPHAM1',columns:['GIA'],rows:[[100]]},table:{name:'SANPHAM1',columns:['GIA'],rows:[[105]]}}]};},
  submitWorkspaceQuestion:async(_id,input)=>{
    if(submissions.some(row=>row.part===input.part && row.solutionId===input.solutionId && row.script===input.script))return {status:"Accepted",message:"Đã nộp",alreadySubmitted:true};
    submissions.push(input);const result={status:'Accepted',message:'Đã nộp câu trả lời.',questionOnly:true,part:input.part,questionId:input.solutionId,submittedScript:input.script,score:1,maxScore:10,scoreVisible:true,
      sections:[{part:input.part,status:'Accepted',message:'Passed',questions:[{id:input.solutionId,name:'secret-add.sql',ratio:1,status:'Accepted',datasets:[{dataset:1,correct:true,message:'Question passed.'}]}]}]};
    history.unshift({id:'q-sub-1',problemId:problem.id,source:'ExamSession',context:'A',questionOnly:true,questionPart:input.part,questionId:input.solutionId,submittedScript:input.script,result:'Accepted',score:1,maxScore:10,scoreVisible:true,submittedAt:'2026-11-01T01:00:00Z',sections:result.sections});return result;
  }
};
const bundle=await build({stdin:{contents:`
import React,{act}from'react';import{createRoot}from'react-dom/client';import{Simulate}from'react-dom/test-utils';import{MemoryRouter}from'react-router-dom';import{ProblemWorkspace}from'./src/features/workspace/ProblemWorkspace';export{act,Simulate};export {submittedQuestionScript} from './src/shared/lib/sql/sqlQuestionDrafts';
export function mount(node){const root=createRoot(node);root.render(<MemoryRouter initialEntries={['/workspace/question-workflow?source=ExamSession&context=A']}><ProblemWorkspace problem={globalThis.__questionProblem}/></MemoryRouter>);return root;}
`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',logLevel:'silent',loader:{'.css':'empty'},plugins:[{name:'question-adapters',setup(builder){
  const stub=(filter,contents)=>builder.onLoad({filter},()=>({contents,loader:'tsx',resolveDir:process.cwd()}));
  stub(/[\\/]studentApi\.ts$/,'export const studentApi=globalThis.__questionApi;');
  stub(/[\\/]AuthContext\.tsx$/,'export const useAuth=()=>({session:{id:"question-student"}});');
  stub(/[\\/]AppHeader\.tsx$/,'export const AppHeader=({workspaceActions,workspaceProblemNavigation})=><header>{workspaceProblemNavigation}{workspaceActions}</header>;');
  stub(/@uiw[\\/]react-codemirror[\\/].*index\.js$/,'export default function CodeMirror(props){return props.readOnly?<pre aria-label={props["aria-label"]}>{props.value}</pre>:<textarea aria-label={props["aria-label"]} value={props.value} onInput={e=>props.onChange(e.currentTarget.value)}/>;}');
}}]});
const file=join(tmpdir(),`question-workflow-${process.pid}.cjs`);let root,api;
try{
  await writeFile(file,bundle.outputFiles[0].contents);api=createRequire(import.meta.url)(file);
  assert.equal(api.submittedQuestionScript('-- DDL\n-- solution:create\nCREATE TABLE T(id INT);\n\n-- DML\n-- solution:update\nUPDATE T SET id=2;\n\n-- solution:empty','DML','update'),'UPDATE T SET id=2;');
  assert.equal(api.submittedQuestionScript('-- DDL\n-- solution:create\nCREATE TABLE T(id INT);\n\n-- DML\n-- solution:empty','DML','empty'),'');
  const click=node=>api.act(async()=>{assert.ok(node,'Control exists');node.click();});
  const change=(node,value)=>api.act(async()=>{node.value=value;api.Simulate.change(node);});
  const stage=()=>document.querySelector('.problem-workspace-stage:not([hidden])');
  const tabs=()=>[...stage().querySelectorAll('.workspace-pane-tabs [role="tab"]')];
  const tab=text=>tabs().find(node=>node.textContent===text);
  const picker=()=>document.querySelector('header .workspace-question-picker select');
  const code=()=>stage().querySelector('.stage-code textarea');
  const panel=()=>stage().querySelector('.workspace-reading-pane .workspace-submission-content');
  const output=()=>stage().querySelector('.workspace-output');
  const edit=value=>api.act(async()=>{code().value=value;code().dispatchEvent(new dom.window.Event('input',{bubbles:true}));});
  await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  assert.deepEqual(tabs().map(node=>node.textContent),['Mô tả','Cơ sở dữ liệu','Bài nộp']);
  assert.equal(stage().querySelector('.workspace-question-picker'),null,'Reading pane has no duplicate question selector');
  assert.deepEqual([...picker().options].map(node=>node.textContent),['Câu 1 DDL','Câu 2 DDL','Câu 3 DDL']);
  await change(picker(),'resize');await click(tab('Cơ sở dữ liệu'));
  assert.match(stage().querySelector('.workspace-database-content').textContent,/GHICHU/);
  assert.match(stage().querySelector('.workspace-database-content').textContent,/varchar\(20\)/);
  assert.equal(preparations[0].solutionId,'resize');
  await click(tab('Mô tả'));await click(tab('Cơ sở dữ liệu'));assert.equal(preparations.length,1,'Reopening a question uses the prepared baseline cache');
  await click(tab('Mô tả'));await change(picker(),'drop');await click(tab('Cơ sở dữ liệu'));
  assert.equal(preparations[1].solutionId,'drop');assert.match(stage().textContent,/GHICHU/);
  await click(tab('Mô tả'));await change(picker(),'add');
  const original='ALTER TABLE SANPHAM ADD GHICHU VARCHAR(20);';
  await edit(original);await click(document.querySelector('.stage-run-actions .primary'));
  assert.ok(stage().querySelector('.workspace-output.is-open'),'Running opens the output panel under the editor');
  assert.equal(output().querySelectorAll('.workspace-result-kind-tabs').length,0,'The output panel shows only the current question');
  assert.deepEqual([...output().querySelectorAll('[role="tab"]')].map(node=>node.textContent),['Thông báo'],'DDL output has only the Messages tab');
  assert.equal(output().querySelectorAll('.status').length,0,'A successful DDL run has no verdict label (only errors are labelled)');
  assert.ok(!output().textContent.includes('CTHD'),'Unchanged tables never enter the run result');
  assert.ok(!output().textContent.includes('Modified table'));assert.ok(!output().textContent.includes('.sql'));
  await edit('ALTER TABLE SANPHAM ADD GHICHU VARCHAR(100);');
  assert.equal(output().querySelector('.workspace-question-submit').disabled,true,'Edited SQL must be run from the editor before submission');
  await click(document.querySelector('.stage-run-actions .primary'));
  assert.equal(output().querySelector('.workspace-question-submit').disabled,false,'Running the updated SQL enables submission');
  await click(output().querySelector('.workspace-question-submit'));
  assert.equal(submissions.length,1);assert.equal(submissions[0].script,'ALTER TABLE SANPHAM ADD GHICHU VARCHAR(100);','Submit uses the updated run snapshot');
  assert.equal(stage().querySelector('.workspace-pane-tabs [aria-selected="true"]').textContent,'Bài nộp');
  const codeDetails=panel().querySelector('.workspace-submission-code');
  await api.act(async()=>{codeDetails.open=true;codeDetails.dispatchEvent(new dom.window.Event('toggle',{bubbles:true}));});
  assert.equal(panel().querySelector('[aria-label="Script đã nộp"]').textContent,'ALTER TABLE SANPHAM ADD GHICHU VARCHAR(100);');
  assert.equal(code().value,'ALTER TABLE SANPHAM ADD GHICHU VARCHAR(100);');
  assert.equal(code().readOnly,false,'A question submission does not lock unrelated drafts');
  await click(output().querySelector('.workspace-question-submit'));
  assert.equal(submissions.length,1,'Clicking an already submitted snapshot does not consume another attempt');
  assert.equal(output().querySelector('.workspace-already-submitted').textContent,'Đã lưu câu này');
  await api.act(async()=>root.unmount());
  await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  if(!stage().querySelector('.workspace-output.is-open'))await click(stage().querySelector('.workspace-output-toggle'));
  assert.ok(output().querySelector('.workspace-output-messages'),'The latest run survives a full remount');
  assert.equal(code().value,'ALTER TABLE SANPHAM ADD GHICHU VARCHAR(100);','The latest draft also survives a full remount');
  await click(tab('Bài nộp'));
  assert.match(panel().textContent,/100%/,'Saved question grades reload from the server');
  assert.equal(code().readOnly,false,'Reloaded question submissions do not become a full-workspace lock');
  await click(document.querySelector('header nav [aria-controls="workspace-stage-DML"]'));
  await click(tab('Mô tả'));await edit('UPDATE SANPHAM1 SET GIA=GIA*1.05;');await click(document.querySelector('.stage-run-actions .primary'));
  assert.equal(lastRun.part,'DML');assert.ok(output().querySelector('.workspace-output-messages'));assert.ok(!output().textContent.includes('KHACHHANG'),'DML output shows messages only, no table data');
  await click(document.querySelector('header nav [aria-controls="workspace-stage-SQL"]'));
  await click(tab('Cơ sở dữ liệu'));
  assert.equal(preparations.at(-1).part,'SQL','DQL loads its question preparation when opening the database pane');
  assert.equal(preparations.at(-1).solutionId,'select');
  assert.ok(stage().textContent.includes('GHICHU'),'The DQL database pane uses executed preparation rather than the original schema');
  await click(document.querySelector('header nav [aria-controls="workspace-stage-TRIGGER"]'));
  await click(tab('Mô tả'));
  assert.ok(stage().textContent.includes('mã khớp đáp án'),'The exact-code trigger grading criterion is visible before editing');
  const trigger="CREATE TRIGGER trg_product ON SANPHAM AFTER INSERT AS SELECT 1;";
  await edit(trigger);
  await change(picker(),'trigger-2');assert.equal(code().value,'','Trigger questions have separate drafts');
  await change(picker(),'trigger-1');assert.equal(code().value,trigger);
  await click(document.querySelector('.stage-run-actions .primary'));
  assert.equal(lastRun.part,'TRIGGER');assert.equal(lastRun.trigger,trigger);assert.equal(lastRun.ddl,'');assert.equal(lastRun.dml,'');
  assert.ok(output().querySelector('.workspace-output-messages').textContent.includes('Lệnh đã chạy thành công.'));
  await click(output().querySelector('.workspace-question-submit'));
  assert.equal(submissions.at(-1).part,'TRIGGER');assert.equal(submissions.at(-1).script,trigger);
  await api.act(async()=>root.unmount());await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  assert.equal(document.querySelector('.problem-workspace-stage:not([hidden])').id,'workspace-stage-TRIGGER');
  assert.equal(code().value,trigger,'Trigger draft survives remount');
  await click(tab('Bài nộp'));assert.ok(panel().textContent.includes('100%'),'Saved trigger grades reload');
  await click([...document.querySelectorAll('header button')].find(button=>button.textContent==='Nộp bài'));
  await click([...document.querySelectorAll('dialog button')].find(button=>button.textContent==='Nộp bài'));
  assert.ok(finalInput.trigger.includes('-- solution:trigger-1'));
  assert.ok(finalInput.trigger.includes(trigger),'Final submission contains the independently saved trigger script');
  console.log('PASS: prepared per-question baselines/cache, Vietnamese labels, run output panel under the editor, submission tab, affected tables only, exact submitted SQL, no duplicate badges/submissions, ordered accordions and reload persistence.');
}finally{if(root)await api.act(async()=>root.unmount());await unlink(file).catch(()=>{});dom.window.close();}
process.exit(0);
