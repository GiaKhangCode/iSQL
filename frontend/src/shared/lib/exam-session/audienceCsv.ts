import { validStudentIdentifier } from "@/data/examinations";

export function parseAudienceCsv(source:string, availableClassIds:string[], mode:"classes"|"students"|"mixed"="mixed") {
  const rows:string[][]=[];let row:string[]=[],cell="",quoted=false;
  const input=source.replace(/^\uFEFF/,"");
  for(let index=0;index<input.length;index++){
    const char=input[index];
    if(char==='"') {if(quoted && input[index+1]==='"'){cell+='"';index++;}else quoted=!quoted;}
    else if(!quoted && [",",";","\t"].includes(char)){row.push(cell.trim());cell="";}
    else if(!quoted && ["\n","\r"].includes(char)){if(char==="\r" && input[index+1]==="\n")index++;row.push(cell.trim());if(row.some(Boolean))rows.push(row);row=[];cell="";}
    else cell+=char;
  }
  if(quoted)throw new Error("CSV contains an unclosed quoted field.");
  row.push(cell.trim());if(row.some(Boolean))rows.push(row);
  if(!rows.length)throw new Error("CSV is empty.");
  if(rows.length>5001)throw new Error("CSV supports up to 5000 rows.");
  const key=(value:string)=>value.normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/đ/g,"d").toLowerCase().replace(/[\s_-]/g,"");
  const classKeys=["classid","class","malop","lop"],studentKeys=["studentid","studentnumber","mssv","masosinhvien","email","studentemail"];
  const headers=rows[0].map(key),hasHeader=headers.some(value=>classKeys.includes(value)||studentKeys.includes(value));
  const classIds=new Set<string>(),students=new Set<string>();
  for(const [index,values] of rows.slice(hasHeader?1:0).entries()){
    values.forEach((value,column)=>{
      if(!value)return;
      const header=hasHeader?headers[column]:"";
      if(hasHeader && !classKeys.includes(header) && !studentKeys.includes(header))return;
      if(mode==="classes" || (mode==="mixed" && (classKeys.includes(header) || (!hasHeader && availableClassIds.includes(value))))){
        if(!availableClassIds.includes(value))throw new Error(`Lớp không khả dụng trong học kỳ hiện tại: ${value}`);
        classIds.add(value);
      }else if(validStudentIdentifier(value.toLowerCase()))students.add(value.toLowerCase());
      else throw new Error(`Dòng ${index+(hasHeader?2:1)}: mã số hoặc email không hợp lệ (${value}).`);
    });
  }
  if(!classIds.size && !students.size)throw new Error("CSV contains no class IDs, student IDs or emails.");
  return {classIds:[...classIds],studentEmails:[...students]};
}
