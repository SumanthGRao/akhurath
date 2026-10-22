import 'dotenv/config';
import express from 'express';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import crypto from 'node:crypto';
import { createStore } from './store.js';
import { authenticateUser } from './auth.js';

const app = express();
const port = Number(process.env.PORT || 3000);
const secret = process.env.SESSION_SECRET;
if (!secret || secret.length < 32) throw new Error('SESSION_SECRET must be at least 32 characters.');
if (process.env.DEMO_MODE === 'false' && (!process.env.DB_HOST || !process.env.DB_USER || !process.env.DB_NAME)) throw new Error('Configure DB_HOST, DB_USER, and DB_NAME when DEMO_MODE=false.');
const store = await createStore(process.env);
app.disable('x-powered-by');
app.use(helmet({ contentSecurityPolicy: { directives: { defaultSrc: ["'self'"], styleSrc: ["'self'", "'unsafe-inline'"], scriptSrc: ["'self'"], imgSrc: ["'self'", 'data:'], connectSrc: ["'self'"] } } }));
app.use(express.json({ limit: '32kb' }));

function sign(value) { return crypto.createHmac('sha256', secret).update(value).digest('base64url'); }
function sessionCookie(user) { const payload = Buffer.from(JSON.stringify({ ...user, exp: Date.now() + 8 * 60 * 60 * 1000 })).toString('base64url'); return `${payload}.${sign(payload)}`; }
function readSession(req) { const token = (req.headers.cookie || '').split(';').map((v) => v.trim()).find((v) => v.startsWith('akhurath_session='))?.slice('akhurath_session='.length); if (!token) return null; const [payload, mac] = token.split('.'); if (!payload || !mac) return null; const expected = sign(payload); const a = Buffer.from(mac); const b = Buffer.from(expected); if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null; try { const user = JSON.parse(Buffer.from(payload, 'base64url').toString()); return user.exp > Date.now() ? user : null; } catch { return null; } }
function requireUser(req, res, next) { const user = readSession(req); if (!user) return res.status(401).json({ error: 'Sign in required.' }); req.user = user; next(); }
function requireAccountant(req, res, next) { if (req.user.role !== 'accountant') return res.status(403).json({ error: 'Accountant access required.' }); next(); }
function requireN8n(req, res, next) { const token = process.env.N8N_API_TOKEN; const supplied = req.get('authorization')?.replace(/^Bearer\s+/i, '') || ''; if (!token || supplied.length !== token.length || !crypto.timingSafeEqual(Buffer.from(supplied), Buffer.from(token))) return res.status(401).json({ error: 'Invalid n8n credentials.' }); next(); }
function sameOrigin(req, res, next) { const origin = req.get('origin'); if (origin && origin !== `${req.protocol}://${req.get('host')}`) return res.status(403).json({ error: 'Cross-origin request rejected.' }); next(); }
const loginLimiter = rateLimit({ windowMs: 15 * 60 * 1000, limit: 10, standardHeaders: true, legacyHeaders: false });
app.get('/api/health', (_req, res) => res.json({ ok: true, mode: store.mode }));
app.post('/api/login', loginLimiter, sameOrigin, (req, res) => { const { username, password } = req.body || {}; if (typeof username !== 'string' || typeof password !== 'string' || username.length > 100 || password.length > 200) return res.status(400).json({ error: 'Enter username and password.' }); const user = authenticateUser(username, password); if (!user) return res.status(401).json({ error: 'Invalid username or password.' }); res.setHeader('Set-Cookie', `akhurath_session=${sessionCookie(user)}; HttpOnly; SameSite=Strict; Path=/; Max-Age=28800${process.env.NODE_ENV === 'production' ? '; Secure' : ''}`); res.json({ user }); });
app.post('/api/logout', sameOrigin, (_req, res) => { res.setHeader('Set-Cookie', `akhurath_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0${process.env.NODE_ENV === 'production' ? '; Secure' : ''}`); res.json({ ok: true }); });
app.get('/api/me', requireUser, (req, res) => res.json({ user: req.user, mode: store.mode }));
app.get('/api/billing', requireUser, async (_req, res, next) => { try { res.json({ items: await store.list() }); } catch (e) { next(e); } });
app.post('/api/billing/:id/invoice', requireUser, requireAccountant, sameOrigin, async (req, res, next) => { const amount = Number(req.body?.bill_amount); const number = String(req.body?.invoice_number || '').trim(); const url = String(req.body?.invoice_file_url || '').trim(); if (!Number.isFinite(amount) || amount <= 0 || amount > 100000000 || !number || number.length > 100) return res.status(400).json({ error: 'Enter a valid amount and invoice number.' }); if (url && (url.length > 2000 || !/^https:\/\//i.test(url))) return res.status(400).json({ error: 'Invoice reference must be an HTTPS URL.' }); try { const row = await store.saveInvoice(req.params.id, { amount, number, url: url || null }, req.user.username); if (!row) return res.status(409).json({ error: 'Invoice cannot be updated for this record.' }); res.json({ item: row }); } catch (e) { next(e); } });
app.post('/api/billing/:id/clear', requireUser, requireAccountant, sameOrigin, async (req, res, next) => { const reference = String(req.body?.payment_reference || '').trim(); if (!reference || reference.length > 255) return res.status(400).json({ error: 'Enter a payment reference before clearing.' }); try { const row = await store.clearPayment(req.params.id, { reference }, req.user.username); if (!row) return res.status(409).json({ error: 'Send an invoice before recording cleared payment.' }); res.json({ item: row, message: 'Payment cleared. The separate n8n workflow may now process delivery.' }); } catch (e) { next(e); } });
app.post('/api/group', requireUser, requireAccountant, sameOrigin, async (req, res, next) => { const ids=[...new Set((Array.isArray(req.body?.ids)?req.body.ids:[]).map(Number).filter((id)=>Number.isSafeInteger(id)&&id>0))];if(ids.length<2||ids.length>50)return res.status(400).json({error:'Select between 2 and 50 projects.'});try{res.json(await store.group(ids,req.user.username));}catch(e){if(e?.status===409)return res.status(409).json({error:e.message});next(e);} });
app.post('/api/ungroup', requireUser, requireAccountant, sameOrigin, async (req, res, next) => { const ids=[...new Set((Array.isArray(req.body?.ids)?req.body.ids:[]).map(Number).filter((id)=>Number.isSafeInteger(id)&&id>0))];if(ids.length<1||ids.length>50)return res.status(400).json({error:'Select between 1 and 50 projects.'});try{res.json(await store.ungroup(ids,req.user.username));}catch(e){if(e?.status===409)return res.status(409).json({error:e.message});next(e);} });
// These endpoints belong to the separate n8n delivery workflow. The dashboard never shares Drive files.
app.get('/api/n8n/eligible', requireN8n, async (_req, res, next) => { try { res.json({ items: await store.eligible() }); } catch (e) { next(e); } });
app.post('/api/n8n/claim/:id', requireN8n, async (req, res, next) => { try { const item = await store.claimEligible(req.params.id); if (!item) return res.status(409).json({ error: 'Record is not eligible; payment clearance and a sent invoice are required.' }); res.json({ item }); } catch (e) { next(e); } });
app.post('/api/n8n/complete/:id', requireN8n, async (req, res, next) => { const claim = String(req.body?.claim_token || ''); const status = req.body?.status; const link = req.body?.final_link ? String(req.body.final_link) : null; const error = req.body?.error ? String(req.body.error).slice(0, 1000) : null; if (!claim || !['delivered', 'failed'].includes(status) || (status === 'delivered' && (!link || !/^https:\/\//i.test(link)))) return res.status(400).json({ error: 'Invalid delivery result.' }); try { const ok = await store.finishDelivery(req.params.id, claim, { status, link, error }); if (!ok) return res.status(409).json({ error: 'Claim is stale or payment is no longer cleared.' }); res.json({ ok: true }); } catch (e) { next(e); } });
app.use(express.static(new URL('../public/', import.meta.url).pathname, { index: 'index.html', maxAge: process.env.NODE_ENV === 'production' ? '1h' : 0 }));
app.use((err, _req, res, _next) => { console.error(err); res.status(500).json({ error: 'Request failed. Check server logs.' }); });
app.listen(port, () => console.log(`Akhurath Billing Dashboard listening on port ${port} (${store.mode} mode)`));
