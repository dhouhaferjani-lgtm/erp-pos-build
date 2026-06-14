import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
const BASE='http://localhost:8089';
const LABEL=process.env.LABEL||'shot';
const EXPECT=process.env.EXPECT||''; // expected main bundle filename; '' = no guard
const OUT='/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-consistency/docs/superpowers/audits/ui-audit-2026-06-14/screens/phase3';
const ROUTES=[
  ['products-list','/inventory/products'],
  ['product-form','/inventory/products/new'],
  ['composite-list','/catalog/composite-items'],
  ['composite-form','/catalog/composite-items/new'],
  ['menu-list','/catalog/menus'],
  ['menu-form','/catalog/menus/new'],
  ['categories','/inventory/categories'],
];
await mkdir(OUT,{recursive:true});
const b=await chromium.launch();const c=await b.newContext({viewport:{width:1440,height:900}});const p=await c.newPage();
const errs=[]; p.on('pageerror',e=>errs.push(String(e)));
await p.goto(`${BASE}/login`,{waitUntil:'domcontentloaded'});
await p.fill('input[name="email"]','owner@cafe-tunis.tn').catch(()=>{});
await p.fill('input[name="password"]','password').catch(()=>{});
await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),p.click('button[type="submit"]').catch(()=>{})]);
await p.waitForTimeout(2000);
for(const [name,route] of ROUTES){
  let served='?';
  for(let i=0;i<6;i++){
    await p.goto(`${BASE}${route}`,{waitUntil:'networkidle',timeout:30000}).catch(()=>{});
    await p.waitForTimeout(1200);
    served=await p.locator('script[type="module"]').first().getAttribute('src').catch(()=>'?');
    if(!EXPECT || (served&&served.includes(EXPECT))) break;
  }
  const guardOk = !EXPECT || (served&&served.includes(EXPECT));
  const h1s=await p.locator('h1').allTextContents();
  const theadHdrs=await p.locator('thead th').allTextContents();
  const errorBoundary=await p.getByText(/something went wrong|application error/i).count();
  const i18nObjErr=await p.getByText(/returned an object instead of string/i).count();
  await p.screenshot({path:`${OUT}/${name}-${LABEL}.png`,fullPage:false}).catch(()=>{});
  console.log(JSON.stringify({name,route,guardOk,bundle:served?.replace(/.*\//,''),h1:h1s.slice(0,2),thead:theadHdrs,errorBoundary,i18nObjErr}));
}
if(errs.length) console.log('PAGEERRORS:',errs.slice(0,5).join(' | '));
await b.close();
