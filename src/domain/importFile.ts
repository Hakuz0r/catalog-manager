export type ImportRow = Record<string, string>
export function parseCsv(text: string): string[][] {
  text=text.replace(/^\uFEFF/,'')
  const first=text.split(/\r?\n/)[0]??''; const counts:Record<string,number>={';':0,'\t':0,',':0};let inQuote=false;for(let i=0;i<first.length;i++){const c=first[i]!;if(c==='\"'){if(inQuote&&first[i+1]==='\"')i++;else inQuote=!inQuote}else if(!inQuote&&c in counts)counts[c]++}const delimiter=Object.entries(counts).sort((a,b)=>b[1]-a[1])[0]![0]
  const rows:string[][]=[]; let row:string[]=[];let cell='';let quoted=false;let closed=false
  for(let i=0;i<text.length;i++){
    const c=text[i]!
    if(c==='"'){if(quoted&&text[i+1]==='"'){cell+='"';i++}else if(quoted){quoted=false;closed=true}else if(!cell&&!closed){quoted=true}else{throw new Error('Некорректная кавычка в CSV. Сохраните файл в стандартном CSV-формате.')}}
    else if(c===delimiter&&!quoted){row.push(cell);cell='';closed=false}
    else if((c==='\n'||c==='\r')&&!quoted){if(c==='\r'&&text[i+1]==='\n')i++;row.push(cell);rows.push(row);row=[];cell='';closed=false}
    else if(closed){if(!/\s/.test(c))throw new Error('После закрывающей кавычки CSV должен быть разделитель.')}
    else cell+=c
  }
  if(quoted)throw new Error('В CSV не закрыта кавычка.')
  if(cell||row.length){row.push(cell);rows.push(row)}
  return rows
}
export function tableToRows(table: unknown[][]): ImportRow[] {
  const hasData=(r:unknown[])=>r.some(v=>v!==null&&v!==undefined&&String(v).trim()!=='')
  const headerIndex=table.findIndex(hasData);if(headerIndex<0)throw new Error('Файл пуст.')
  const headers=table[headerIndex]!.map(v=>String(v??'').replace(/^\uFEFF/,'').trim())
  if(headers.some(h=>!h||h==='__row')||headers.length>80||new Set(headers.map(h=>h.toLowerCase())).size!==headers.length)throw new Error('Заголовки должны быть непустыми, уникальными; максимум 80 колонок.')
  const rows=table.slice(headerIndex+1).map((row,i)=>({row,sourceRow:headerIndex+i+2})).filter(({row})=>hasData(row))
  if(!rows.length)throw new Error('Нужна хотя бы одна строка товаров.')
  if(rows.length>200)throw new Error('В одной пачке максимум 200 товаров. Разделите файл на несколько частей.')
  return rows.map(({row:r,sourceRow})=>{if(r.length>headers.length&&r.slice(headers.length).some(v=>v!==null&&v!==undefined&&String(v)!==''))throw new Error(`В строке ${sourceRow} больше значений, чем заголовков.`);return Object.fromEntries([...headers.map((h,k)=>[h,String(r[k]??'').trim()]),['__row',String(sourceRow)]])})
}
// XLSX is a ZIP: bound its declared uncompressed data before the parser allocates it.
// ZIP64/encrypted/multidisk archives are unnecessary for a 200-row import.
export function validateXlsxArchive(buffer: ArrayBuffer): void {
  const view = new DataView(buffer); const fail = () => { throw new Error('Небезопасный или слишком большой XLSX: до 1024 файлов, 16 МБ на файл и 32 МБ после распаковки. Сохраните только нужный лист в обычный XLSX или CSV.') }
  if (view.byteLength < 22) fail()
  let end = -1
  for (let i=view.byteLength-22; i>=Math.max(0, view.byteLength-65557); i--) {
    if (view.getUint32(i,true)===0x06054b50 && i+22+view.getUint16(i+20,true)===view.byteLength) { end=i; break }
  }
  if (end<0) fail()
  const count=view.getUint16(end+10,true); const size=view.getUint32(end+12,true); const start=view.getUint32(end+16,true)
  if (view.getUint16(end+4,true)!==0 || view.getUint16(end+6,true)!==0 || view.getUint16(end+8,true)!==count || count<1 || count>1024 || start+size!==end) fail()
  let cursor=start; let total=0
  for (let i=0;i<count;i++) {
    if (cursor+46>end || view.getUint32(cursor,true)!==0x02014b50) fail()
    const compressed=view.getUint32(cursor+20,true); const original=view.getUint32(cursor+24,true); const local=view.getUint32(cursor+42,true)
    const flags=view.getUint16(cursor+8,true); const method=view.getUint16(cursor+10,true)
    const length=46+view.getUint16(cursor+28,true)+view.getUint16(cursor+30,true)+view.getUint16(cursor+32,true)
    total+=original
    if ((flags&1)!==0 || ![0,8].includes(method) || original>16*1024*1024 || total>32*1024*1024 || compressed>buffer.byteLength || cursor+length>end || local+30>start || view.getUint32(local,true)!==0x04034b50) fail()
    if (local+30+view.getUint16(local+26,true)+view.getUint16(local+28,true)+compressed>start) fail()
    cursor+=length
  }
  if (cursor!==end) fail()
}
export async function readImportFile(file: File): Promise<ImportRow[]> {
  if(file.size>5*1024*1024)throw new Error('Файл должен быть не больше 5 МБ.')
  if(/\.csv$/i.test(file.name))return tableToRows(parseCsv(await file.text()))
  if(/\.xlsx$/i.test(file.name)){const buffer=await file.arrayBuffer();validateXlsxArchive(buffer);const {readSheet}=await import('read-excel-file/browser');return tableToRows(await readSheet(buffer))}
  throw new Error('Поддерживаются .xlsx и .csv. Старый .xls сохраните как .xlsx или CSV.')
}
export function downloadTemplate(){
  const body='\uFEFFID;Артикул;Название;Категория;Цена;Единица;Статус;Характеристика:Материал\r\n'
  const url=URL.createObjectURL(new Blob([body],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='Шаблон_импорта_товаров.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)
}
