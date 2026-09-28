'use strict';

/*
 * Odměny – výpočty a importy bez vazby na stránku.
 *
 * Logika pochází z původní aplikace hodnoceni-operatoru.html. Změny proti ní
 * jsou označené komentářem „Oprava:“ a hlídá je test tests/test_core.js, který
 * porovnává výsledky s původním souborem.
 */


/* Oprava: id z kryptografického generátoru místo Math.random. */
const uid=p=>p+Array.from(crypto.getRandomValues(new Uint8Array(5)),b=>(b%36).toString(36)).join("");
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
const esc=s=>String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const norm=s=>String(s==null?"":s).normalize("NFD").replace(/[̀-ͯ]/g,"").toLowerCase().replace(/\s+/g," ").trim();
const nf=(n,d=1)=>Number(n||0).toLocaleString("cs-CZ",{minimumFractionDigits:0,maximumFractionDigits:d});
const pct=n=>Math.round(n*100);
const DOW=["Ne","Po","Út","St","Čt","Pá","So"];
const MON=["led","úno","bře","dub","kvě","čvn","čvc","srp","zář","říj","lis","pro"];
const MONL=["leden","únor","březen","duben","květen","červen","červenec","srpen","září","říjen","listopad","prosinec"];

/* ---------- svátky ČR ---------- */
function easter(y){const a=y%19,b=Math.floor(y/100),c=y%100,d=Math.floor(b/4),e=b%4,f=Math.floor((b+8)/25),
  g=Math.floor((b-f+1)/3),h=(19*a+b-d-g+15)%30,i=Math.floor(c/4),k=c%4,l=(32+2*e+2*i-h-k)%7,
  m=Math.floor((a+11*h+22*l)/451),mo=Math.floor((h+l-7*m+114)/31),da=((h+l-7*m+114)%31)+1;
  return new Date(Date.UTC(y,mo-1,da));}
const _holCache={};
function holidays(y){
  if(_holCache[y])return _holCache[y];
  const h={"1-1":"Nový rok","5-1":"Svátek práce","5-8":"Den vítězství","7-5":"Cyril a Metoděj","7-6":"Jan Hus",
    "9-28":"Den české státnosti","10-28":"Vznik ČSR","11-17":"Den boje za svobodu",
    "12-24":"Štědrý den","12-25":"1. svátek vánoční","12-26":"2. svátek vánoční"};
  const e=easter(y);
  const gf=new Date(e.getTime()-2*864e5), em=new Date(e.getTime()+864e5);
  h[(gf.getUTCMonth()+1)+"-"+gf.getUTCDate()]="Velký pátek";
  h[(em.getUTCMonth()+1)+"-"+em.getUTCDate()]="Velikonoční pondělí";
  return _holCache[y]=h;
}
function mkDay(y,m,d){
  const dt=new Date(Date.UTC(y,m-1,d));
  const dow=dt.getUTCDay();
  const hol=holidays(y)[m+"-"+d]||null;
  return {y,m,d,iso:y+"-"+String(m).padStart(2,"0")+"-"+String(d).padStart(2,"0"),dow,
    sat:dow===6,sun:dow===0,weekend:dow===0||dow===6,hol,work:dow!==0&&dow!==6&&!hol};
}

/* ---------- výchozí data ----------
   Hodnocení v tabácích: úroveň (1-4) dává základ, docházka ho automaticky sníží
   nebo zvýší podle pravidel pozice, pak sankce a ruční úprava.
   Kafe je samostatná fajfka: odpracované hodiny >= práh (výchozí = fond). */
const LEVEL_NAMES=["Nováček","Pokročilý","Samostatný","Profík"];
const DEF_PENALTY=[{at:1,t:1},{at:2,t:2}];     // chybí od X prac. dnů -> -t tabáků
const DEF_BONUS=[{at:2,t:1},{at:3,t:2}];       // od X víkendových směn -> +t tabáků
/* Sankce se zadávají u každého člověka zvlášť: důvod ze společného seznamu + srážka 10/30/50 %
   z nároku (úroveň + docházka, po stropu pozice). Procenta se sčítají, 2x 50 % = 0. */
const SAN_PCTS=[10,30,50];
const DEF_SAN_REASONS=["Nedodržení BOZP","Nedodržení výrobních předpisů","Malá produktivita",
  "Nedodržování stanovených přestávek","Zničení dílů"];
function mkPos(id,name,descs){
  return {id,name,max:null,          // null = strop podle nejvyšší úrovně
    levels:LEVEL_NAMES.map((n,i)=>({name:n,tabaky:(i+1)*2,desc:descs[i]||""})),
    penalty:JSON.parse(JSON.stringify(DEF_PENALTY)),
    bonus:JSON.parse(JSON.stringify(DEF_BONUS))};
}
const DEF_POSITIONS=[
  mkPos("p_cnc","CNC",["Zaučuje se, pracuje pod dohledem.","Plně samostatný na běžných dílech.",
    "Zvládá hliník i plast.","Zvládá náročné projekty, zaučuje ostatní."]),
  mkPos("p_kon","Kontrola",["Zaučuje se, měří pod dohledem.","Samostatně měří běžné díly.",
    "Měření na 3D.","Uvolňuje první kus, řeší reklamace."]),
  mkPos("p_bru","Brusič",["Zaučuje se, pracuje pod dohledem.","Samostatný na běžných dílech.",
    "Zvládá tenké díly.","Zvládá náročné díly, zaučuje ostatní."]),
  mkPos("p_pis","Pískování",["Zaučuje se, pracuje pod dohledem.","Samostatný.",
    "Sám nastaví tlak a zrno.","Náročné povrchy, zaučuje ostatní."]),
  mkPos("p_lep","Lepení / Značení",["Zaučuje se, pracuje pod dohledem.","Samostatný.",
    "Laserové značení.","Náročné zakázky, zaučuje ostatní."]),
  mkPos("p_dai","Daiho",["Zaučuje se, pracuje pod dohledem.","Plně samostatný.",
    "Sám seřídí stroj.","Zvládá náročné projekty, zaučuje ostatní."])
];
const DEF_SETTINGS={
  shift:7.5,
  excused:"D,DOV,N,NEM,PN,L,P,OČR,ŠK,S,V,NV",
  excusedReducesFund:false,
  friShift:6.5,            // zkrácený pátek - platí jen pro lidi se zaškrtnutým shortFri
  absenceCutoff:null,      // od kolika dní absence (po vyplnění víkendem) nemá nárok na nic; null = vypnuto
  adjStep:1,
  adjOver:2,               // o kolik smí ruční úprava jít nad maximum pozice
  hiddenCols:[],           // skryté sloupce přehledu
  lockMinutes:15           // zamčení po nečinnosti (minuty)
};
const n2=v=>Math.round((+v||0)*100)/100;
const sgn=v=>(v>0?"+":v<0?"−":"")+Math.abs(Math.round(v));
function tabW(n){n=Math.abs(Math.round(n));return n===1?"tabák":(n>=2&&n<=4)?"tabáky":"tabáků";}

/* ---------- stav ---------- */
let S=null;
/* Po každé změně stavu; aplikace sem připojí šifrované ukládání. */
let onStateChange=null;
function save(){_prodIdx=null;if(typeof onStateChange==="function")onStateChange();}

function blank(){return {positions:JSON.parse(JSON.stringify(DEF_POSITIONS)),employees:{},periods:{},adjust:{},
  sanctions:[],sanReasons:DEF_SAN_REASONS.slice(),production:{},prodMap:{},kafe:{},issued:{},current:null,
  settings:{...DEF_SETTINGS},demo:false};}
/* Převod ze staršího modelu (procenta + zaškrtávané podkategorie) na úrovně a tabáky. */
function migrate(o){
  o.positions.forEach(p=>{
    if(!Array.isArray(p.levels)||p.levels.length!==4){
      const max=Math.max(1,Math.round(+p.max||8));
      const old=Array.isArray(p.skills)?p.skills:[];
      p.levels=LEVEL_NAMES.map((n,i)=>({name:n,tabaky:Math.max(1,Math.round(max*(i+1)/4)),
        desc:old[i]?old[i].name:""}));
      p._old=true;                     // staré body-maximum nepřenášíme, strop dopočítá nejvyšší úroveň
    }
    if(!Array.isArray(p.penalty))p.penalty=JSON.parse(JSON.stringify(DEF_PENALTY));
    if(!Array.isArray(p.bonus))p.bonus=JSON.parse(JSON.stringify(DEF_BONUS));
  });
  Object.values(o.employees).forEach(e=>{
    if(e.level!==undefined)return;
    const p=o.positions.find(x=>x.id===e.positionId);
    if(p&&Array.isArray(p.skills)&&p.skills.length){
      const tot=p.skills.reduce((s,x)=>s+(+x.pts||0),0)||1;
      const set=new Set(e.skills||[]);
      const have=p.skills.filter(x=>set.has(x.id)).reduce((s,x)=>s+(+x.pts||0),0);
      e.level=clamp(Math.ceil(have/tot*4),1,4);   // podíl zaškrtnutých -> úroveň 1..4
    }else e.level=p?1:null;
  });
  o.positions.forEach(p=>{if(p._old){delete p.skills;p.max=null;delete p._old;}
    if(p.max===undefined)p.max=null;});
  Object.values(o.employees).forEach(e=>{delete e.skills;});
  ["wAtt","wSkill","wFund","wOt","otBase","bonusOverMax"].forEach(k=>{delete o.settings[k];});
  o.kafe=o.kafe||{};
  o.issued=o.issued||{};
  if(!Array.isArray(o.sanReasons))o.sanReasons=DEF_SAN_REASONS.slice();
  return o;
}

/* ruční úprava tabáků - drží se u období, ne u člověka */
function adjKey(){return S.current||"_";}
function adjOf(key){return (S.adjust[adjKey()]||{})[key]||0;}
function setAdj(key,val){
  const b=S.adjust[adjKey()]||(S.adjust[adjKey()]={});
  const v=Math.round(val);
  if(!v)delete b[key];else b[key]=v;
  save();
}
/* sankce - záznam u člověka a měsíce; nové mají pct (10/30/50), starší záznamy mají pevné points */
function sanFor(key,period){return S.sanctions.filter(s=>s.key===key&&s.period===period);}
function sanLabel(s){
  return s.pct!=null?s.name+" "+s.pct+" %"+(s.note?" – "+s.note:"")
                    :(s.reason||"Sankce")+" (−"+(+s.points||0)+" tab., starý záznam)";
}
function reasonsOf(key){return sanFor(key,adjKey()).map(sanLabel).join("; ");}
/* potvrzení výdeje benefitu - u měsíce, se snímkem toho, co se vydalo */
function issuedOf(key){const b=S.issued&&S.issued[adjKey()];return b&&b[key]?b[key]:null;}
function setIssued(key,val){
  S.issued=S.issued||{};
  const b=S.issued[adjKey()]||(S.issued[adjKey()]={});
  if(val)b[key]=val;else delete b[key];
  save();
}
function shortDate(ts){return new Date(ts).toLocaleDateString("cs-CZ",{day:"numeric",month:"numeric"});}

/* ---------- parsování ---------- */
function serialToYMD(n){
  const ms=Math.round((n-25569)*864e5);
  const dt=new Date(ms);
  return {y:dt.getUTCFullYear(),m:dt.getUTCMonth()+1,d:dt.getUTCDate()};
}
function cellToDate(v){
  if(v==null||v==="")return null;
  if(v instanceof Date)return {y:v.getFullYear(),m:v.getMonth()+1,d:v.getDate()};
  if(typeof v==="number"){
    if(v>=20000&&v<80000)return serialToYMD(v);
    if(Number.isInteger(v)&&v>=1&&v<=31)return {d:v};
    return null;
  }
  const s=String(v).trim();
  if(!s)return null;
  let m=s.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
  if(m)return {y:+m[1],m:+m[2],d:+m[3]};
  m=s.match(/(\d{1,2})\s*[.\/-]\s*(\d{1,2})(?:\s*[.\/-]\s*(\d{2,4}))?/);
  if(m){const o={d:+m[1],m:+m[2]};if(m[3])o.y=+m[3]<100?2000+ +m[3]:+m[3];return o;}
  m=s.match(/^\D{0,3}(\d{1,2})\.?$/);
  if(m&&+m[1]>=1&&+m[1]<=31)return {d:+m[1]};
  return null;
}
function parseHours(v){
  if(v==null||v==="")return {h:0,code:null};
  if(typeof v==="number")return {h:v>0&&v<=24?v:0,code:null};
  if(v instanceof Date)return {h:0,code:null};
  const s=String(v).trim();
  if(!s)return {h:0,code:null};
  const t=s.replace(/\s/g,"").replace(",",".");
  if(/^\d+(\.\d+)?$/.test(t)){const n=parseFloat(t);return {h:n<=24?n:0,code:null};}
  const m=t.match(/^(\d+(?:\.\d+)?)(.*)$/);
  if(m){const rest=(m[2]||"").replace(/[^\p{L}]/gu,"").toUpperCase();
    return {h:parseFloat(m[1])<=24?parseFloat(m[1]):0,code:rest||null};}
  const code=t.replace(/[^\p{L}]/gu,"").toUpperCase();
  return {h:0,code:code||null};
}
function parseCSV(text){
  const sep=(text.split("\n")[0].match(/;/g)||[]).length>=(text.split("\n")[0].match(/,/g)||[]).length?";":",";
  const rows=[];let row=[],cur="",q=false;
  for(let i=0;i<text.length;i++){
    const c=text[i];
    if(q){ if(c==='"'){ if(text[i+1]==='"'){cur+='"';i++;} else q=false; } else cur+=c; }
    else if(c==='"')q=true;
    else if(c===sep){row.push(cur);cur="";}
    else if(c==="\n"){row.push(cur);rows.push(row);row=[];cur="";}
    else if(c!=="\r")cur+=c;
  }
  row.push(cur);rows.push(row);
  return rows.map(r=>r.map(c=>{const t=c.trim();if(t==="")return null;
    const n=t.replace(",",".");return /^-?\d+(\.\d+)?$/.test(n)?parseFloat(n):t;}));
}
const SKIP_NAMES=["jmeno","jméno","prijmeni","příjmení","celkem","soucet","součet","osobni cislo","zamestnanec"];

/* Převede AOA na období. Vrací {ok,err,warn,period} */
function buildPeriod(aoa,fileName,forceY,forceM){
  const warn=[];
  if(!aoa||!aoa.length)return {ok:false,err:"Soubor je prázdný."};
  const width=Math.max(...aoa.map(r=>r?r.length:0));
  if(width<6)return {ok:false,err:"List má míň než 6 sloupců - docházka má začínat ve sloupci F."};
  // hlavička = řádek s nejvíc daty od sloupce F
  let hr=-1,best=0;
  for(let r=0;r<Math.min(aoa.length,25);r++){
    const row=aoa[r]||[];let c=0;
    for(let i=5;i<width;i++)if(cellToDate(row[i]))c++;
    if(c>best){best=c;hr=r;}
  }
  if(hr<0||best<5)return {ok:false,err:"Nenašel jsem řádek s daty. Očekávám datum (nebo čísla dnů) od sloupce F."};
  // období
  const full=[];
  for(let i=5;i<width;i++){const p=cellToDate((aoa[hr]||[])[i]);if(p&&p.y&&p.m)full.push(p);}
  let Y=forceY,M=forceM;
  if(!Y||!M){
    if(full.length){const cnt={};full.forEach(p=>{const k=p.y+"-"+p.m;cnt[k]=(cnt[k]||0)+1;});
      const k=Object.keys(cnt).sort((a,b)=>cnt[b]-cnt[a])[0].split("-");Y=+k[0];M=+k[1];}
    else{const now=new Date();const prev=new Date(now.getFullYear(),now.getMonth()-1,1);
      Y=prev.getFullYear();M=prev.getMonth()+1;
      warn.push("V hlavičce byla jen čísla dnů bez měsíce - použil jsem "+MONL[M-1]+" "+Y+". Změňte v poli nad tabulkou, pokud nesedí.");}
  }
  // sloupce -> dny
  const cols=[];
  for(let i=5;i<width;i++){
    const p=cellToDate((aoa[hr]||[])[i]);
    if(!p||!p.d)continue;
    const y=p.y||Y, m=p.m||M;
    if(p.y&&p.m&&(y!==Y||m!==M))continue;          // jiný měsíc v témže listu ignorujeme
    if(p.d<1||p.d>31)continue;
    const dim=new Date(Date.UTC(y,m,0)).getUTCDate();
    if(p.d>dim)continue;
    cols.push({col:i,day:mkDay(y,m,p.d)});
  }
  if(!cols.length)return {ok:false,err:"Z hlavičky se nepodařilo poskládat žádný den."};
  cols.sort((a,b)=>a.day.iso<b.day.iso?-1:1);
  const days=cols.map(c=>c.day);
  // řádky lidí
  const rows={},codes={};let dupes=0;
  for(let r=hr+1;r<aoa.length;r++){
    const row=aoa[r]||[];
    const a=row[0]==null?"":String(row[0]).trim();
    const b=row[1]==null?"":String(row[1]).trim();
    if(!a&&!b)continue;
    if(SKIP_NAMES.includes(norm(a))||SKIP_NAMES.includes(norm(b)))continue;
    if(/celkem|součet|soucet|suma/i.test(a+" "+b))continue;
    const first=a,last=b||a;
    const key=norm(last)+"|"+norm(first);
    if(rows[key])dupes++;
    const hrs=[],cds=[];
    let any=false;
    cols.forEach(c=>{
      const p=parseHours(row[c.col]);
      hrs.push(p.h);cds.push(p.code);
      if(p.h>0||p.code)any=true;
      if(p.code)codes[p.code]=(codes[p.code]||0)+1;
    });
    if(!any&&!b)continue;                            // nadpisové bloky bez dat
    rows[key]={first,last,h:hrs,c:cds};
  }
  if(!Object.keys(rows).length)return {ok:false,err:"Nenašel jsem žádné zaměstnance ve sloupcích A a B pod hlavičkou."};
  if(dupes)warn.push(dupes+"&times; se opakovalo stejné jméno - ponechal jsem poslední řádek.");
  return {ok:true,warn,period:{id:Y+"-"+String(M).padStart(2,"0"),y:Y,m:M,days:days.map(d=>d.iso),
    rows,codes,file:fileName,at:Date.now(),headerRow:hr+1}};
}

/* ---------- evidence práce (vyrobené kusy) ---------- */
function cellToDateTime(v){
  if(v==null||v==="")return null;
  if(v instanceof Date)return {y:v.getFullYear(),m:v.getMonth()+1,d:v.getDate()};
  if(typeof v==="number")return (v>=20000&&v<80000)?serialToYMD(Math.floor(v)):null;
  const s=String(v).trim();
  let m=s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
  if(m)return {y:+m[1],m:+m[2],d:+m[3]};
  m=s.match(/^(\d{1,2})\s*[.\/-]\s*(\d{1,2})\s*[.\/-]\s*(\d{2,4})/);
  if(m)return {y:+m[3]<100?2000+ +m[3]:+m[3],m:+m[2],d:+m[1]};
  return null;
}
function toNum(v){
  if(v==null||v==="")return null;
  if(typeof v==="number")return isFinite(v)?v:null;
  const t=String(v).replace(/\s/g,"").replace(",",".");
  return /^-?\d+(\.\d+)?$/.test(t)?parseFloat(t):null;
}
/* "Petr Dvořák" -> klíč zaměstnance; ruční spárování má přednost */
function prodKeyFor(raw){
  const n=norm(raw);
  if(!n)return null;
  if(S.prodMap[n]&&S.employees[S.prodMap[n]])return S.prodMap[n];
  const t=n.split(" ").filter(Boolean);
  if(t.length<2)return null;
  const asFirstLast=t.slice(1).join(" ")+"|"+t[0];          // Jméno Příjmení
  if(S.employees[asFirstLast])return asFirstLast;
  const asLastFirst=t.slice(0,-1).join(" ")+"|"+t[t.length-1]; // Příjmení Jméno
  if(S.employees[asLastFirst])return asLastFirst;
  if(t.length>2){                                            // prostřední jméno navíc
    const a=t[t.length-1]+"|"+t[0];
    if(S.employees[a])return a;
  }
  return null;
}
let _prodIdx=null;
function prodPeriod(){return S.current?S.production[S.current]:null;}
function prodIndex(){
  if(_prodIdx)return _prodIdx;
  const idx={},pp=prodPeriod();
  if(pp)Object.values(pp.names).forEach(rec=>{
    const key=prodKeyFor(rec.name);
    if(!key)return;
    const a=idx[key]||(idx[key]={total:0,entries:0,days:{},sources:[]});
    a.total+=rec.total;a.entries+=rec.count;a.sources.push(rec.name);
    Object.entries(rec.days).forEach(([iso,ks])=>{a.days[iso]=(a.days[iso]||0)+ks;});
  });
  Object.values(idx).forEach(a=>{
    a.prodDays=Object.keys(a.days).length;              // dnů, kdy něco zapsal
    a.avgEntryDay=a.prodDays?a.total/a.prodDays:0;      // jen doplňkový údaj
  });
  return _prodIdx=idx;
}
function productionOf(key){return prodIndex()[key]||null;}
/* Průměr na den se počítá vůči odpracovaným dnům z docházky, ne vůči dnům se zápisem:
   díl přes několik dnů se zapíše jednou, ale pracovalo se na něm celou dobu. */
function workedDays(att){return att?(att.workDays+att.wkDays):0;}
function avgPerDay(prod,att){const d=workedDays(att);return (prod&&d)?prod.total/d:null;}
function avgTitle(prod,att){
  if(!prod)return "";
  const d=workedDays(att);
  if(!d)return nf(prod.total,0)+" ks, ale v docházce nemá odpracovaný žádný den";
  return nf(prod.total,0)+" ks ÷ "+d+" odpracovaných dnů ("+att.workDays+" všedních + "+att.wkDays+" víkendových)";
}
function prodUnmatched(){
  const pp=prodPeriod();if(!pp)return [];
  return Object.values(pp.names).filter(rec=>!prodKeyFor(rec.name)).sort((a,b)=>b.total-a.total);
}
function buildProduction(aoa,fileName){
  if(!aoa||!aoa.length)return {ok:false,err:"Soubor je prázdný."};
  const buckets={};let used=0,skipped=0;
  for(let r=0;r<aoa.length;r++){
    const row=aoa[r]||[];
    const nm=row[2]==null?"":String(row[2]).trim();
    const dt=cellToDateTime(row[3]);
    const ks=toNum(row[4]);
    if(!nm||!dt||ks==null||ks<=0){
      if(nm&&(row[3]!=null||row[4]!=null)&&!SKIP_NAMES.includes(norm(nm)))skipped++;
      continue;
    }
    if(SKIP_NAMES.includes(norm(nm)))continue;
    const pid=dt.y+"-"+String(dt.m).padStart(2,"0");
    const iso=pid+"-"+String(dt.d).padStart(2,"0");
    const b=buckets[pid]||(buckets[pid]={id:pid,names:{},records:0,total:0,file:fileName,at:Date.now()});
    const k=norm(nm);
    const rec=b.names[k]||(b.names[k]={name:nm,total:0,count:0,days:{}});
    rec.total+=ks;rec.count++;rec.days[iso]=(rec.days[iso]||0)+ks;
    b.records++;b.total+=ks;used++;
  }
  if(!used)return {ok:false,err:"Nenašel jsem žádný použitelný řádek. Čekám jméno ve sloupci C, datum ve sloupci D a počet kusů ve sloupci E."};
  return {ok:true,buckets,used,skipped};
}

/* ---------- výpočty ---------- */
function periodDays(p){return p.days.map(iso=>{const[a,b,c]=iso.split("-").map(Number);return mkDay(a,b,c);});}
function excusedSet(){return new Set(S.settings.excused.split(/[,;\s]+/).map(s=>norm(s).toUpperCase()).filter(Boolean));}
function periodMeta(p){
  const days=periodDays(p);
  const work=days.filter(d=>d.work).length;
  const sats=days.filter(d=>d.sat).length;
  const suns=days.filter(d=>d.sun).length;
  const hols=days.filter(d=>d.hol&&!d.weekend).length;
  return {days,work,sats,suns,hols,otCap:sats,fund:work*S.settings.shift};
}
/* délka směny pro konkrétní den: kdo má zkrácený pátek, má v pátek plnou směnu už při friShift hodinách */
function friShift(){const f=+S.settings.friShift;return f>0?f:S.settings.shift;}
function dayShift(d,shortFri){return (shortFri&&d.dow===5)?friShift():S.settings.shift;}
function attendance(p,key){
  const meta=periodMeta(p), row=p.rows[key];
  const ex=excusedSet();
  const emp=S.employees[key], sf=!!(emp&&emp.shortFri);
  const r={found:!!row,workDays:0,workHours:0,cappedHours:0,wkDays:0,wkHours:0,otWeek:0,
    missed:0,excusedDays:0,excusedHours:0,codes:{},cells:[],totalDays:0,totalHours:0,
    shortFri:sf,fridays:0,absDays:0,shortHours:0,shortDays:0};
  let fund=0;
  meta.days.forEach((d,i)=>{
    const h=row?(row.h[i]||0):0, c=row?(row.c[i]||null):null;
    /* Oprava: kód z buňky se porovnává stejně normalizovaný jako seznam (OČR = OCR). */
    const isEx=!!(c&&ex.has(norm(c).toUpperCase())), ds=dayShift(d,sf);
    if(c)r.codes[c]=(r.codes[c]||0)+1;
    if(h>0){r.totalDays++;r.totalHours+=h;}
    if(d.work){
      fund+=ds;
      if(d.dow===5)r.fridays++;
      /* absence = celý pracovní den bez docházky; kratší směna není absence (projeví se jen v hodinách a u Kafe) */
      if(h>0){r.workDays++;r.workHours+=h;r.cappedHours+=Math.min(h,ds);
        r.otWeek+=Math.max(0,h-ds);
        if(h<ds-1e-9){r.shortDays++;r.shortHours+=ds-h;}
      }else if(isEx){r.excusedDays++;r.excusedHours+=ds;
        if(!S.settings.excusedReducesFund)r.absDays++;
      }else{r.missed++;r.absDays++;}
    }else if(h>0){r.wkDays++;r.wkHours+=h;}
    r.cells.push({d,h,c,isEx,ds});
  });
  if(S.settings.excusedReducesFund)fund=Math.max(0,fund-r.excusedHours);
  r.fund=fund;
  r.fundRatio=fund>0?clamp(r.cappedHours/fund,0,1):1;
  r.otCap=meta.otCap;
  /* o kolik je osobní fond nižší než společný (kvůli zkrácenému pátku) - sníží i práh Kafe */
  r.friDiff=sf?Math.max(0,r.fridays*(S.settings.shift-friShift())):0;
  return r;
}
function posOf(e){return S.positions.find(p=>p.id===e.positionId)||null;}
function levelOf(e){
  const pos=posOf(e);
  const n=(pos&&e.level>=1&&e.level<=4)?e.level:0;
  const lv=n?pos.levels[n-1]:null;
  return {pos,n,lv,base:lv?Math.max(0,Math.round(+lv.tabaky||0)):0};
}
/* nejvyšší splněný řádek pravidel: {at: práh, t: tabáky} */
function pickRule(rules,val){
  let best=null;
  (rules||[]).forEach(r=>{const at=+r.at||0;if(at>0&&val>=at&&(!best||at>+best.at))best=r;});
  return best;
}
/* Docházka v tabácích.
   Chybějící hodiny ve všední dny -> celé dny (zaokrouhleno, 7,5 h = 1 den).
   Každá víkendová směna vyplní jeden chybějící den. Co zbyde chybět -> srážka podle pravidel pozice.
   Když nic nechybí, zbylé víkendové směny -> bonus podle pravidel pozice. */
function attTabaky(a,pos){
  const r={gross:0,filled:0,missing:0,wkLeft:0,delta:0,rule:null,kind:null};
  if(!a||!a.found)return r;
  r.gross=a.absDays;                          // dny absence (celé dny bez docházky)
  r.filled=Math.min(a.wkDays,r.gross);
  r.missing=r.gross-r.filled;
  r.wkLeft=a.wkDays-r.filled;
  if(!pos)return r;
  if(r.missing>0){
    const ru=pickRule(pos.penalty,r.missing);
    if(ru){r.rule=ru;r.kind="pen";r.delta=-Math.abs(Math.round(+ru.t||0));}
  }else{
    const ru=pickRule(pos.bonus,r.wkLeft);
    if(ru){r.rule=ru;r.kind="bonus";r.delta=Math.abs(Math.round(+ru.t||0));}
  }
  return r;
}
/* Kafe: globální práh hodin pro vybraný měsíc; bez ručního nastavení = fond (prac. dny x směna) */
function kafeThreshold(p){
  if(!p)return null;
  const o=S.kafe?S.kafe[p.id]:null;
  return (o!=null&&o!==""&&isFinite(+o))?+o:periodMeta(p).fund;
}
function kafeIsCustom(p){return !!(p&&S.kafe&&S.kafe[p.id]!=null&&S.kafe[p.id]!=="");}
/* prázdná hodnota nebo přesně fond = bez vlastního prahu, takže bude dál sledovat fond */
function setKafe(v){
  const p=curPeriod();if(!p)return;
  S.kafe=S.kafe||{};
  const t=String(v==null?"":v).trim().replace(",",".");
  if(t===""||!isFinite(+t)||Math.abs(+t-periodMeta(p).fund)<1e-9)delete S.kafe[p.id];
  else S.kafe[p.id]=Math.max(0,n2(+t));
}
/* strop pozice: ruční hodnota, jinak nejvyšší úroveň */
function posMaxAuto(p){return p?Math.max(0,...p.levels.map(l=>Math.round(+l.tabaky||0))):0;}
function posMax(p){
  if(!p)return 0;
  return (p.max!=null&&p.max!==""&&isFinite(+p.max))?Math.max(0,Math.round(+p.max)):posMaxAuto(p);
}
/* srážka v tabácích z % součtu: zaokrouhlení na celé, ale každá sankce stojí aspoň 1 tabák; 100 % a víc = vše */
function sanDeduct(narok,pctSum,count=1){
  if(narok<=0||pctSum<=0)return 0;
  if(pctSum>=100)return narok;
  /* Oprava: aspoň 1 tabák za každou sankci (dřív jen 1 celkem, i když nápověda slibovala každou). */
  return Math.min(narok,Math.max(Math.max(1,count),Math.round(narok*pctSum/100)));
}
function cutoffDays(){const c=+S.settings.absenceCutoff;return (S.settings.absenceCutoff!=null&&c>0)?Math.round(c):0;}
function evaluate(e,p){
  const a=p?attendance(p,e.key):null;
  const L=levelOf(e);
  const at=attTabaky(a,L.pos);
  const max=posMax(L.pos);
  /* ztráta nároku: při velké absenci nedostane nic - ani tabák, ani kafe, ani ruční úpravou */
  const cut=cutoffDays();
  const lost=!!(a&&a.found&&cut&&at.missing>=cut);
  const raw=L.base+at.delta;
  const capped=!lost&&raw>max;                 // úroveň + docházka nikdy nad maximum pozice
  const narok=lost?0:Math.max(0,Math.min(raw,max));
  const sans=sanFor(e.key,adjKey());
  const pctSum=Math.min(100,sans.reduce((x,s)=>x+(s.pct!=null?+s.pct||0:0),0));
  const pctCount=sans.filter(s=>s.pct!=null&&+s.pct>0).length;
  const legacy=sans.reduce((x,s)=>x+(s.pct==null?Math.round(+s.points||0):0),0);
  const sanT=sanDeduct(narok,pctSum,pctCount);
  const pen=Math.min(narok,sanT+legacy);       // tabáky stržené sankcemi
  const sub=narok-pen;
  /* ruční úprava drží výsledek v 0..maximum + povolený přesah (jen ručně, bonus za víkendy strop nepřekročí) */
  const over=Math.max(0,Math.round(+S.settings.adjOver||0));
  const adjMin=-sub, adjMax=lost?0:Math.max(0,max+over-sub);
  const adj=Math.round(clamp(adjOf(e.key),adjMin,adjMax));
  const total=clamp(sub+adj,0,lost?0:max+over);
  const kth0=kafeThreshold(p);
  const kth=kth0==null?null:Math.max(0,kth0-(a?a.friDiff:0));   // zkrácený pátek sníží i práh Kafe
  const kafe=!lost&&!!(a&&a.found&&kth!=null&&a.totalHours>=kth-1e-9);
  const issued=p?issuedOf(e.key):null;
  const issuedChanged=!!(issued&&(issued.tabaky!==total||!!issued.kafe!==kafe));
  const toIssue=total>0||kafe;
  return {att:a,L,at,pos:L.pos,max,raw,capped,narok,lost,cut,attEff:lost?-L.base:narok-L.base,
    issued,issuedChanged,toIssue,
    sans,pctSum,pctCount,sanT,legacy,pen,sub,adj,adjMin,adjMax,total,
    kafe,kafeTh:kth,pctv:max?pct(Math.min(1,total/max)):0};
}
function allRows(){
  const p=S.current?S.periods[S.current]:null;
  return Object.values(S.employees).map(e=>({e,p,prod:productionOf(e.key),...evaluate(e,p)}));
}
/* vyřazení ze seznamu: člověk zůstává v evidenci, ale nehodnotí se a nikde se nenabízí */
function activeRows(){return allRows().filter(r=>!r.e.excluded);}
function excludedPeople(){return Object.values(S.employees).filter(e=>e.excluded)
  .sort((a,b)=>norm(a.last).localeCompare(norm(b.last),"cs"));}

function curPeriod(){return S.current?S.periods[S.current]:null;}
function periodName(p){return p?MONL[p.m-1]+" "+p.y:"bez importu";}

function issueWhat(tab,kafe){return tab+" "+tabW(tab)+(kafe?" + kafe":"");}

function dnW(n){n=Math.abs(n);return n===1?"den":(n>=2&&n<=4)?"dny":"dní";}
function smW(n){n=Math.abs(n);return n===1?"směna":(n>=2&&n<=4)?"směny":"směn";}

/* lidsky čitelné vysvětlení, proč vyšla srážka / bonus za docházku */
function attExplain(r){
  const a=r.att,t=r.at;
  if(!(a&&a.found))return "Bez importované docházky";
  /* "Absence 4 − 3 víkendy = 1": ať je vidět celý počet, ne jen výsledek */
  const calc=t.filled?"absence "+t.gross+" − "+t.filled+" "+(t.filled===1?"víkend":"víkendy")+" = "+t.missing:"absence "+t.gross;
  if(r.lost)return "Bez nároku: "+calc+", hranice je "+r.cut+" — ani tabák, ani kafe";
  if(!r.pos)return "Bez pozice — pravidla docházky se neuplatní";
  if(t.kind==="pen")return calc+" → pravidlo ≥ "+t.rule.at+": −"+Math.abs(t.delta)+
    (t.wkLeft?"":", bonus ne (víkendy padly na vyplnění)");
  if(t.kind==="bonus")return (t.filled?calc+", ":"bez absence, ")+t.wkLeft+" víkend. "+smW(t.wkLeft)+" navíc → pravidlo ≥ "+t.rule.at+": +"+t.delta+
    (r.capped?", zastropováno na maximum pozice "+r.max+" (připsáno +"+Math.max(0,r.attEff)+")":"");
  if(t.missing>0)return calc+" — žádné pravidlo srážky nesedí";
  if(t.filled)return calc+" — vše vyplněno víkendem"+(t.wkLeft?", navíc "+t.wkLeft+" — pod prahem bonusu":"");
  return "Bez absence"+(t.wkLeft?", "+t.wkLeft+" víkend. "+smW(t.wkLeft)+" — pod prahem bonusu":"");
}

function thisMonthId(){const d=new Date();return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0");}
function monthLabel(id){const p=String(id||"").split("-");
  return p.length===2&&MONL[+p[1]-1]?MONL[+p[1]-1]+" "+p[0]:(id||"—");}
function nameOf(key){const e=S.employees[key];return e?e.last+" "+e.first:key;}

/* ---------------- export ---------------- */
function exportRows(rows){
  const p=curPeriod();
  /* jedna definice sloupce = hlavička, buňka i to, jestli se sčítá */
  const C=[
    {t:"Příjmení",v:r=>r.e.last},
    {t:"Jméno",v:r=>r.e.first},
    {t:"Pozice",v:r=>r.pos?r.pos.name:""},
    {t:"Úroveň",v:r=>r.L.n||""},
    {t:"Název úrovně",v:r=>r.L.n?r.L.lv.name:""},
    {t:"Zkrácený pátek",v:r=>r.e.shortFri?"ano":""},
    {t:"Odpracované dny",v:r=>r.att&&r.att.found?r.att.workDays:"",s:1},
    {t:"Hodiny celkem",v:r=>r.att&&r.att.found?n2(r.att.totalHours):"",s:1},
    {t:"Fond hodin",v:r=>r.att&&r.att.found?n2(r.att.fund):""},
    {t:"Kafe práh (h)",v:r=>r.kafeTh!=null?n2(r.kafeTh):""},
    {t:"Kafe",v:r=>r.att&&r.att.found?(r.kafe?"ano":"ne"):""},
    {t:"Víkendové směny",v:r=>r.att&&r.att.found?r.att.wkDays:"",s:1},
    {t:"Absence (dny)",v:r=>r.att&&r.att.found?r.at.gross:"",s:1},
    {t:"Vyplněno víkendem",v:r=>r.att&&r.att.found?r.at.filled:"",s:1},
    {t:"Bez nároku",v:r=>r.lost?"ano":""},
    {t:"Omluvená absence (dny)",v:r=>r.att&&r.att.found?r.att.excusedDays:"",s:1},
    {t:"Tabáky za úroveň",v:r=>r.L.base,s:1},
    {t:"Docházka ± (podle pravidla)",v:r=>r.at.delta,s:1},
    {t:"Pravidlo docházky",v:r=>attExplain(r)},
    {t:"Maximum pozice",v:r=>r.pos?r.max:""},
    {t:"Nárok",v:r=>r.narok,s:1},
    {t:"Sankce %",v:r=>r.pctSum||0},
    {t:"Sankce (tabáky)",v:r=>r.pen?-r.pen:0,s:1},
    {t:"Důvody sankcí",v:r=>reasonsOf(r.e.key)},
    {t:"Ruční úprava",v:r=>r.adj,s:1},
    {t:"Tabáky celkem",v:r=>r.total,s:1},
    {t:"Vydáno dne",v:r=>r.issued?new Date(r.issued.at).toLocaleDateString("cs-CZ"):""},
    {t:"Vydáno tabáků",v:r=>r.issued?r.issued.tabaky:"",s:1},
    {t:"Vydáno kafe",v:r=>r.issued?(r.issued.kafe?"ano":"ne"):""},
    {t:"Změna od výdeje",v:r=>r.issuedChanged?"ano":""},
    {t:"Vyrobené kusy",v:r=>r.prod?n2(r.prod.total):"",s:1},
    {t:"Ø kusů na odpracovaný den",v:r=>{const a=avgPerDay(r.prod,r.att);return a==null?"":n2(a);}},
    {t:"Ø kusů na hodinu",v:r=>(r.prod&&r.att&&r.att.totalHours)?n2(r.prod.total/r.att.totalHours):""},
    {t:"Zápisů v evidenci",v:r=>r.prod?r.prod.entries:"",s:1}
  ];
  const head=C.map(c=>c.t);
  const body=rows.map(r=>C.map(c=>c.v(r)));
  body.push(C.map((c,i)=>i===0?"CELKEM":i===1?rows.length+" lidí":
    c.s?n2(rows.reduce((x,r)=>x+(+c.v(r)||0),0)):""));
  return {head,body,title:"Hodnoceni "+(p?p.id:"bez-obdobi")};
}

/* ---------------- export a import zařazení ----------------
   Cíl: zařazení na pozice a podkategorie vyplnit jednou a pak jen načítat.
   Soubor jde editovat v Excelu a poslat zpátky - matchuje se na příjmení + jméno. */
function rosterSheets(){
  const people=Object.values(S.employees).sort((a,b)=>norm(a.last).localeCompare(norm(b.last),"cs"));
  const main=[["Příjmení","Jméno","Pozice","Úroveň (1-4)","Název úrovně","Tabáky za úroveň","Zkrácený pátek","Vyřazen ze seznamu","Poznámka"]];
  people.forEach(e=>{const L=levelOf(e);
    main.push([e.last,e.first,L.pos?L.pos.name:"",L.n||"",L.n?L.lv.name:"",L.n?L.base:"",e.shortFri?"ano":"",e.excluded?"ano":"",e.note||""]);});
  const posSheet=[["Pozice","Úroveň","Název úrovně","Tabáky","Co úroveň obnáší"]];
  S.positions.forEach(p=>p.levels.forEach((lv,i)=>posSheet.push([p.name,i+1,lv.name,+lv.tabaky||0,lv.desc||""])));
  const rules=[["Pozice","Typ","Práh","Tabáky","Význam"]];
  S.positions.forEach(p=>{
    rules.push([p.name,"maximum","",posMax(p),p.max!=null&&p.max!==""?"vlastní strop pozice":"podle nejvyšší úrovně"]);
    (p.penalty||[]).forEach(r=>rules.push([p.name,"srážka",+r.at,-Math.abs(+r.t||0),"chybí aspoň "+r.at+" prac. "+dnW(+r.at)]));
    (p.bonus||[]).forEach(r=>rules.push([p.name,"bonus",+r.at,Math.abs(+r.t||0),"aspoň "+r.at+" víkend. "+smW(+r.at)+" navíc"]));
  });
  return {main,posSheet,rules};
}

function applyRoster(aoa){
  if(!aoa||!aoa.length)return {err:"Soubor je prázdný."};
  let hr=-1,map={};
  for(let r=0;r<Math.min(aoa.length,15);r++){
    const row=(aoa[r]||[]).map(c=>norm(c));
    const m={};row.forEach((c,i)=>{
      if(c.startsWith("prijmeni"))m.last=i;
      else if(c.startsWith("jmeno"))m.first=i;
      else if(c.startsWith("pozice"))m.pos=i;
      else if(c.startsWith("uroven")&&m.lvl==null)m.lvl=i;
      else if(c.startsWith("nazev uroven"))m.lvlName=i;
      else if(c.startsWith("zkraceny patek"))m.fri=i;
      else if(c.startsWith("vyrazen"))m.excl=i;
      else if(c.startsWith("poznamka"))m.note=i;});
    if(m.last!=null&&m.first!=null&&m.pos!=null){hr=r;map=m;break;}
  }
  if(hr<0)return {err:"Nenašel jsem hlavičku se sloupci Příjmení, Jméno, Pozice."};
  const findPos=name=>{const n=norm(name);if(!n)return null;
    let p=S.positions.find(x=>norm(x.name)===n);
    if(!p){p=mkPos(uid("p"),String(name).trim(),[]);S.positions.push(p);}
    return p;};
  /* úroveň: číslo 1-4, nebo název úrovně dané pozice (např. "Profík") */
  const readLevel=(pos,v,vName)=>{
    const t=norm(v);
    if(/^[1-4]$/.test(t))return +t;
    const byName=nm=>{const n=norm(nm);if(!n||!pos)return 0;
      const i=pos.levels.findIndex(l=>norm(l.name)===n);return i>=0?i+1:0;};
    return byName(v)||byName(vName)||0;
  };
  let touched=0,created=0,newPos=S.positions.length,noLvl=0;
  for(let r=hr+1;r<aoa.length;r++){
    const row=aoa[r]||[];
    const last=String(row[map.last]==null?"":row[map.last]).trim();
    const first=String(row[map.first]==null?"":row[map.first]).trim();
    if(!last&&!first)continue;
    if(SKIP_NAMES.includes(norm(last))||SKIP_NAMES.includes(norm(first)))continue;
    const key=norm(last)+"|"+norm(first);
    let e=S.employees[key];
    if(!e){e=S.employees[key]={key,first,last,positionId:null,level:null,note:""};created++;}
    const pos=findPos(row[map.pos]);
    e.positionId=pos?pos.id:null;
    if(map.lvl!=null||map.lvlName!=null){
      const n=readLevel(pos,map.lvl!=null?row[map.lvl]:null,map.lvlName!=null?row[map.lvlName]:null);
      if(n)e.level=n;else if(pos)noLvl++;
    }
    if(pos&&!e.level)e.level=1;
    const yes=v=>["ano","a","1","x","true","yes","ok"].includes(norm(v));
    if(map.fri!=null)e.shortFri=yes(row[map.fri]);
    if(map.excl!=null)e.excluded=yes(row[map.excl]);
    if(map.note!=null&&row[map.note]!=null)e.note=String(row[map.note]).trim();
    touched++;
  }
  newPos=S.positions.length-newPos;
  if(!touched)return {err:"Pod hlavičkou nebyla žádná jména."};
  return {touched,created,newPos,noLvl};
}

/* ---------------- ukázková data ---------------- */
function rng(seed){let s=seed>>>0;return()=>{s=(s*1664525+1013904223)>>>0;return s/4294967296;};}
function demoData(){
  const st=blank();st.demo=true;
  const now=new Date(),pm=new Date(now.getFullYear(),now.getMonth()-1,1);
  const Y=pm.getFullYear(),M=pm.getMonth()+1;
  const dim=new Date(Date.UTC(Y,M,0)).getUTCDate();
  const days=[];for(let d=1;d<=dim;d++)days.push(mkDay(Y,M,d));
  const people=[
    ["Petr","Dvořák","p_cnc",4,0,4],["Jana","Svobodová","p_kon",3,1,2],["Martin","Novák","p_cnc",2,2,1],
    ["Lukáš","Procházka","p_bru",3,0,3],["Eva","Kučerová","p_lep",2,1,0],["Tomáš","Veselý","p_dai",4,0,2],
    ["Marie","Horáková","p_pis",1,3,0],["Jiří","Černý","p_dai",2,1,1]
  ];
  const rows={},codes={};
  people.forEach((p,i)=>{
    const [first,last,posId,lvl,miss,wk]=p;
    const sf=last==="Svobodová";            // ukázka zkráceného pátku
    const r=rng(i*7919+Y*31+M);
    const h=[],c=[];let left=miss,sat=wk;
    days.forEach(d=>{
      if(d.work){
        if(left>0&&r()<0.28){left--;h.push(0);c.push("D");codes.D=(codes.D||0)+1;}
        else if(sf&&d.dow===5){h.push(6.5);c.push(null);}
        else{const x=r();h.push(x<0.06?6:x>0.93?9:7.5);c.push(null);}
      }else if(d.sat&&sat>0){sat--;h.push(r()<0.4?6:7.5);c.push(null);}
      else{h.push(0);c.push(null);}
    });
    const key=norm(last)+"|"+norm(first);
    rows[key]={first,last,h,c};
    st.employees[key]={key,first,last,positionId:posId,level:lvl,shortFri:sf,note:""};
  });
  const id=Y+"-"+String(M).padStart(2,"0");
  /* evidence práce - jeden zápis denně, u Veselého schválně s překlepem v příjmení,
     aby bylo na čem ukázat ruční spárování */
  const names={};
  people.forEach((p,i)=>{
    const first=p[0], last=p[1];
    const shown=(last==="Veselý"?"Veselí":last);
    const r=rng(i*104729+Y+M), base=[38,26,44,31,52,29,61,35][i%8];
    const key=norm(first+" "+shown);
    const rec={name:first+" "+shown,total:0,count:0,days:{}};
    const row=rows[norm(last)+"|"+norm(first)];
    days.forEach((d,j)=>{
      if(!row||!(row.h[j]>0))return;
      const ks=Math.max(1,Math.round(base*(0.72+r()*0.56)*(row.h[j]/7.5)));
      rec.days[d.iso]=ks;rec.total+=ks;rec.count++;
    });
    if(rec.count)names[key]=rec;
  });
  st.production={};
  st.production[id]={id,names,file:"ukázka-evidence-prace.xlsx",at:Date.now(),
    records:Object.values(names).reduce((x,r)=>x+r.count,0),
    total:Object.values(names).reduce((x,r)=>x+r.total,0)};
  const sk=(last,first,name,pctV,note)=>({id:uid("x"),key:norm(last)+"|"+norm(first),period:id,name,pct:pctV,note,at:Date.now()});
  st.sanctions=[
    sk("Dvořák","Petr","Zničení dílů",30,"2 ks hřídele, špatné upnutí"),
    sk("Novák","Martin","Nedodržování stanovených přestávek",10,""),
    sk("Kučerová","Eva","Nedodržení BOZP",50,"bez brýlí u brusky"),
    sk("Kučerová","Eva","Zničení dílů",50,"")
  ];
  st.periods[id]={id,y:Y,m:M,days:days.map(d=>d.iso),rows,codes,file:"ukázka-dochazka.xlsx",at:Date.now(),headerRow:1};
  st.current=id;
  /* výdej dopočítá init() z opravdového hodnocení; Dvořákovi "vydáme" o 2 víc, jako by to bylo před sankcí */
  st._demoIssue=[["prochazka|lukas",0],["dvorak|petr",2]];
  return st;
}

/* ---------------- načtení a kontrola dat ----------------
   Data přicházejí ze serveru (šifrovaná), ze zálohy nebo z JSON exportu původní
   aplikace. Každý vstup se převede do známého tvaru: neznámé klíče zmizí, čísla se
   omezí na rozumný rozsah a id mohou obsahovat jen bezpečné znaky. */
const SAFE_ID=/^[A-Za-z0-9_-]{1,40}$/;
const PERIOD_ID=/^\d{4}-\d{2}$/;
const ISO_DAY=/^\d{4}-\d{2}-\d{2}$/;
const OV_COLUMN_KEYS=["days","hours","wk","kafe","lvl","prod","att","pen","adj","iss"];
function sanitizeState(input){
  const o=(input&&typeof input==="object"&&!Array.isArray(input))?input:{};
  const obj=v=>(v&&typeof v==="object"&&!Array.isArray(v))?v:{};
  const arr=v=>Array.isArray(v)?v:[];
  const str=(v,max)=>String(v==null?"":v).slice(0,max);
  const num=(v,d,lo,hi)=>{const n=Number(v);return Number.isFinite(n)?clamp(n,lo,hi):d;};
  const int=(v,d,lo,hi)=>Math.round(num(v,d,lo,hi));
  const rules=list=>arr(list).filter(r=>r&&typeof r==="object").slice(0,20).map(r=>({at:int(r.at,1,1,31),t:int(r.t,0,0,100)}));
  /* klíče z dat nesmí přepsat prototyp objektu (__proto__ apod.) */
  const key=(v,max)=>{const k=str(v,max);return (k==="__proto__"||k==="constructor"||k==="prototype")?"":k;};
  const pid=v=>(PERIOD_ID.test(String(v))||v==="_")?String(v):"";
  /* převod ze staršího tvaru (původní load + migrate) */
  o.settings={...DEF_SETTINGS,...obj(o.settings)};
  o.positions=arr(o.positions).filter(x=>x&&typeof x==="object");
  o.employees=obj(o.employees);o.periods=obj(o.periods);o.adjust=obj(o.adjust);
  o.sanctions=arr(o.sanctions);o.production=obj(o.production);o.prodMap=obj(o.prodMap);
  Object.keys(o.employees).forEach(k=>{if(!o.employees[k]||typeof o.employees[k]!=="object")delete o.employees[k];});
  migrate(o);

  const out=blank();
  out.demo=!!o.demo;
  out.positions=o.positions.filter(p=>typeof p.id==="string"&&SAFE_ID.test(p.id)).slice(0,100).map(p=>({
    id:p.id,name:str(p.name,80),
    max:(p.max==null||p.max==="")?null:int(p.max,0,0,200),
    levels:LEVEL_NAMES.map((n,i)=>{const l=obj(arr(p.levels)[i]);
      return {name:str(l.name==null?n:l.name,40),tabaky:int(l.tabaky,(i+1)*2,0,100),desc:str(l.desc,500)};}),
    penalty:rules(p.penalty),bonus:rules(p.bonus)
  }));
  const posIds=new Set(out.positions.map(p=>p.id));
  Object.entries(o.employees).slice(0,5000).forEach(([key_,e])=>{
    const k=key(key_,200);if(!k)return;
    out.employees[k]={key:k,first:str(e.first,80),last:str(e.last,80),
      positionId:posIds.has(e.positionId)?e.positionId:null,
      level:(e.level>=1&&e.level<=4)?Math.round(e.level):null,
      note:str(e.note,500)};
    if(e.shortFri)out.employees[k].shortFri=true;
    if(e.excluded)out.employees[k].excluded=true;
  });
  Object.entries(o.periods).forEach(([id,p])=>{
    if(!PERIOD_ID.test(id)||!p||typeof p!=="object")return;
    const days=arr(p.days).filter(d=>typeof d==="string"&&ISO_DAY.test(d)&&d.startsWith(id)).slice(0,31);
    if(!days.length)return;
    const rows={};
    Object.entries(obj(p.rows)).slice(0,5000).forEach(([k,r])=>{
      if(!r||typeof r!=="object"||!key(k,200))return;
      rows[key(k,200)]={first:str(r.first,80),last:str(r.last,80),
        h:days.map((_,i)=>num(arr(r.h)[i],0,0,24)),
        c:days.map((_,i)=>{const c=arr(r.c)[i];return c==null||c===""?null:str(c,16);})};
    });
    const codes={};Object.entries(obj(p.codes)).slice(0,200).forEach(([c,n])=>{if(key(c,16))codes[key(c,16)]=int(n,0,0,100000);});
    out.periods[id]={id,y:+id.slice(0,4),m:+id.slice(5,7),days,rows,codes,file:str(p.file,200),at:num(p.at,0,0,1e15),headerRow:int(p.headerRow,1,1,1000)};
  });
  Object.entries(o.adjust).forEach(([id,b])=>{
    const box={};Object.entries(obj(b)).forEach(([k,v])=>{const n=int(v,0,-100,100);if(n&&key(k,200))box[key(k,200)]=n;});
    if(pid(id)&&Object.keys(box).length)out.adjust[pid(id)]=box;
  });
  out.sanctions=o.sanctions.filter(x=>x&&typeof x==="object"&&typeof x.key==="string").slice(0,20000).map(x=>{
    const base={id:typeof x.id==="string"&&SAFE_ID.test(x.id)?x.id:uid("x"),key:str(x.key,200),period:PERIOD_ID.test(String(x.period))?String(x.period):"",
      note:str(x.note,300),at:num(x.at,Date.now(),0,1e15)};
    if(x.pct!=null)return {...base,name:str(x.name,100),pct:SAN_PCTS.includes(+x.pct)?+x.pct:int(x.pct,10,0,100)};
    return {...base,reason:str(x.reason,100),points:int(x.points,0,0,1000)};
  }).filter(x=>x.period);
  out.sanReasons=arr(o.sanReasons).map(r=>str(r,100).trim()).filter(Boolean).slice(0,60);
  if(!Array.isArray(o.sanReasons))out.sanReasons=DEF_SAN_REASONS.slice();
  Object.entries(o.production).forEach(([id,pp])=>{
    if(!PERIOD_ID.test(id)||!pp||typeof pp!=="object")return;
    const names={};
    Object.entries(obj(pp.names)).slice(0,5000).forEach(([n,rec])=>{
      if(!rec||typeof rec!=="object"||!key(n,200))return;
      const days={};Object.entries(obj(rec.days)).forEach(([iso,ks])=>{if(ISO_DAY.test(iso))days[iso]=num(ks,0,0,1e9);});
      names[key(n,200)]={name:str(rec.name,160),total:num(rec.total,0,0,1e12),count:int(rec.count,0,0,1e9),days};
    });
    out.production[id]={id,names,records:int(pp.records,0,0,1e9),total:num(pp.total,0,0,1e12),file:str(pp.file,200),at:num(pp.at,0,0,1e15)};
  });
  Object.entries(o.prodMap).forEach(([n,k])=>{if(typeof k==="string"&&key(n,200)&&key(k,200))out.prodMap[key(n,200)]=key(k,200);});
  Object.entries(obj(o.kafe)).forEach(([id,v])=>{if(PERIOD_ID.test(id)&&v!==""&&v!=null&&Number.isFinite(+v))out.kafe[id]=num(v,0,0,1000);});
  Object.entries(obj(o.issued)).forEach(([id,b])=>{
    const box={};Object.entries(obj(b)).forEach(([k,v])=>{if(v&&typeof v==="object"&&key(k,200))box[key(k,200)]={at:num(v.at,0,0,1e15),tabaky:int(v.tabaky,0,0,1000),kafe:!!v.kafe};});
    if(pid(id)&&Object.keys(box).length)out.issued[pid(id)]=box;
  });
  const st=obj(o.settings);
  out.settings={
    shift:num(st.shift,7.5,1,24),
    excused:str(st.excused==null?DEF_SETTINGS.excused:st.excused,300),
    excusedReducesFund:!!st.excusedReducesFund,
    friShift:num(st.friShift,6.5,1,24),
    absenceCutoff:(st.absenceCutoff==null||st.absenceCutoff===""||!(+st.absenceCutoff>0))?null:int(st.absenceCutoff,1,1,31),
    adjStep:int(st.adjStep,1,1,5),
    adjOver:int(st.adjOver,2,0,10),
    hiddenCols:arr(st.hiddenCols).filter(k=>OV_COLUMN_KEYS.includes(k)),
    lockMinutes:int(st.lockMinutes,15,1,240)
  };
  out.current=(typeof o.current==="string"&&PERIOD_ID.test(o.current))?o.current:null;
  if(!out.current||!out.periods[out.current])out.current=Object.keys(out.periods).sort().reverse()[0]||null;
  return out;
}

/* Oprava: CSV z Excelu bývá ve Windows-1250, z Google Tabulek a Macu v UTF-8.
   Původní aplikace četla vše jako Windows-1250 a UTF-8 soubory měly rozbitou diakritiku. */
function decodeText(buffer){
  const bytes=new Uint8Array(buffer);
  if(bytes[0]===0xEF&&bytes[1]===0xBB&&bytes[2]===0xBF)return new TextDecoder("utf-8").decode(bytes.subarray(3));
  try{return new TextDecoder("utf-8",{fatal:true}).decode(bytes);}
  catch(e){return new TextDecoder("windows-1250").decode(bytes);}
}

/* Oprava: buňka začínající =, +, − nebo @ by se v Excelu spustila jako vzorec. */
function safeCell(v){
  if(typeof v!=="string")return v;
  return /^[=+\-@\t\r]/.test(v)?"'"+v:v;
}

if(typeof module==="object"&&module.exports){
  module.exports={setState:v=>{S=v;_prodIdx=null;},getState:()=>S};
}
