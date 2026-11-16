# Email on Render free plan

Render's free plan blocks outbound SMTP (ports 25, 465, 587), so Gmail SMTP can't work there.
MBUS sends the verification / reset codes over HTTPS (port 443) when one of these is set in Render > Environment:

## Option A: Gmail through a Google Apps Script (no sign-up, free, ~100 emails/day)
1. Open https://script.google.com signed in as the sender Gmail > New project.
2. Paste this, change `SECRET`:
```js
const SECRET = 'put-a-long-random-string-here';
function doPost(e) {
  try {
    const d = JSON.parse(e.postData.contents);
    if (d.secret !== SECRET) return out({ok:false, error:'bad secret'});
    MailApp.sendEmail({to: d.to, subject: d.subject, htmlBody: d.html, body: d.text, name: 'MBUS System'});
    return out({ok:true});
  } catch (err) { return out({ok:false, error:String(err)}); }
}
function out(o){ return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON); }
```
3. Deploy > New deployment > Web app > Execute as **Me**, Who has access **Anyone** > Deploy, accept the permissions.
4. Copy the Web app URL. In Render add `MAIL_RELAY_URL` = that URL and `MAIL_RELAY_SECRET` = the same SECRET. Save, rebuild, deploy.

## Option B: Brevo (300 emails/day)
Create a Brevo account, verify your sender email, make an API key. In Render add `BREVO_API_KEY` and `MAIL_FROM_EMAIL` (the verified sender).

If neither is set, MBUS falls back to SMTP (`SMTP_*`), which only works on a paid Render instance or locally.
