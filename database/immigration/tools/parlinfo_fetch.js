#!/usr/bin/env node
// Fetch Library of Parliament (Parlinfo) birthplaces from a machine Parlinfo accepts, for upload to the loader host.
//
// The Parlinfo web API refuses the loader host's datacenter address, so the bulk fetch runs from a workstation:
//   1. ids:    ssh govdata-serving "mysql -N grantconts -e \"SELECT lop_person_id FROM parl_person_birth
//              WHERE lop_person_id REGEXP '^[0-9]+$'\"" > ids.txt
//   2. fetch:  node parlinfo_fetch.js ids.txt parlinfo-import.jsonl      (resumable; ~0.6 s per profile)
//   3. upload: scp parlinfo-import.jsonl govdata-serving:/var/lib/govdata-imm/raw/parlinfo-import.jsonl
// The next `run.sh countries` imports the file (countries.load_parlinfo) and merges it.
//
// Only the birth fields are written; the profile's other personal fields (ethnicity among them) are not kept.
const fs = require('fs');

const [, , idsFile, outFile] = process.argv;
if (!idsFile || !outFile) { console.error('usage: node parlinfo_fetch.js <ids.txt> <out.jsonl>'); process.exit(2); }
const URL = 'https://loppublicservices.parl.ca/ParlinfoWebApi/Person/GetPersonWebProfile/';
const ids = fs.readFileSync(idsFile, 'utf8').split(/\r?\n/).map(l => l.trim().split(/\s+/)[0]).filter(x => /^\d+$/.test(x));
const done = new Set();
if (fs.existsSync(outFile)) {
  for (const l of fs.readFileSync(outFile, 'utf8').split('\n')) { try { done.add(String(JSON.parse(l).lop_person_id)); } catch {} }
}
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  let n = 0, failures = 0;
  for (const id of ids) {
    if (done.has(id)) continue;
    let row = null;
    for (let attempt = 0; attempt < 3 && !row; attempt++) {
      try {
        const res = await fetch(URL + id, { headers: { 'User-Agent': 'govdata.ca-loader/1.0 (+https://www.govdata.ca)', Accept: 'application/json' },
                                            signal: AbortSignal.timeout(30000) });
        if (res.status === 404) { row = { lop_person_id: +id, http_status: 404 }; break; }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const p = (await res.json()).Person || {};
        row = { lop_person_id: +id, http_status: 200, name: [p.UsedFirstName, p.LastName].filter(Boolean).join(' '),
                birth_date: (p.DateOfBirth || '').slice(0, 10) || null, birth_city: p.CityOfBirthEn || null,
                birth_province: p.ProvinceOfBirthEn || null, birth_country: p.CountryOfBirthEn || null,
                birth_hidden: p.DoNotDiscloseBirthLocation ? 1 : 0, fetched_at: new Date().toISOString().slice(0, 19).replace('T', ' ') };
      } catch (e) {
        await sleep(5000 * (attempt + 1));
      }
    }
    if (!row) {
      failures++;
      if (failures >= 10) { console.error('10 consecutive failures; stopping (resume later)'); process.exit(1); }
      continue;
    }
    failures = 0;
    fs.appendFileSync(outFile, JSON.stringify(row) + '\n');
    if (++n % 100 === 0) console.log(new Date().toISOString(), n, 'fetched');
    await sleep(600);
  }
  console.log('done', n, 'fetched this run');
})();
