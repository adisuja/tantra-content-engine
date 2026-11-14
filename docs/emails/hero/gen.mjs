import fs from 'node:fs';
const KEY = process.env.KIE_API_KEY_2;
const kie = async (url, opts = {}) => (await fetch(url, { ...opts, headers: { Authorization: `Bearer ${KEY}`, 'Content-Type': 'application/json' } })).json();
const before = (await kie('https://api.kie.ai/api/v1/chat/credit')).data;
const prompt = process.argv[2];
const { data } = await kie('https://api.kie.ai/api/v1/jobs/createTask', { method: 'POST', body: JSON.stringify({ model: 'seedream/4.5-text-to-image', input: { prompt, aspect_ratio: '16:9', quality: 'basic' } }) });
console.log('task', data?.taskId);
for (let i = 0; i < 90; i++) {
  await new Promise((r) => setTimeout(r, 4000));
  const d = (await kie(`https://api.kie.ai/api/v1/jobs/recordInfo?taskId=${data.taskId}`)).data;
  if (d?.state === 'success') {
    const url = JSON.parse(d.resultJson).resultUrls[0];
    fs.writeFileSync(process.argv[3], Buffer.from(await (await fetch(url)).arrayBuffer()));
    const after = (await kie('https://api.kie.ai/api/v1/chat/credit')).data;
    console.log(JSON.stringify({ ok: true, file: process.argv[3], credits_before: before, credits_after: after, spent_credits: +(before - after).toFixed(2), spent_usd: +((before - after) * 0.005).toFixed(3) }));
    process.exit(0);
  }
  if (d?.state === 'fail') { console.log('fail', d.failMsg); process.exit(1); }
}
