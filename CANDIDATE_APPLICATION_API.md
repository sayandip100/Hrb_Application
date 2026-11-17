# Candidate application API — frontend handoff

Implemented in `C:/xampp85/htdocs/hrb-api`. Gateway integration is deferred.
Registration verifies email by OTP; subsequent login uses mobile, advertisement and password.
One candidate account/application is associated with one advertisement. The same mobile can apply to another advertisement by registering there separately.

## 1. Setup and browser session

The additive migration `2026_11_12_065843_create_candidate_application_tables` creates `candidate_applications` (draft/version/state) and `candidate_documents` (private upload metadata). It has been applied to the configured PostgreSQL `hrb` database. Do not run all pending default Laravel migrations against the migrated database.

Redis must be running. Candidate OTPs default to Redis (`CANDIDATE_AUTH_CACHE_STORE=redis`). Email delivery uses Laravel's configured SMTP mailer (`MAIL_MAILER=smtp`, existing MAIL_HOST/MAIL_PORT/MAIL_USERNAME/MAIL_PASSWORD/MAIL_FROM_ADDRESS settings). Keep credentials private. Codes expire in 300 seconds, resends have a 60-second cooldown, and five failed code attempts invalidate a challenge.

Call `GET /sanctum/csrf-cookie` before registration/login. Expected response: **204, empty body**. Preserve both session and XSRF cookies. Send `Accept: application/json` on requests and the URL-decoded XSRF cookie in `X-XSRF-TOKEN` on POST/PATCH/DELETE. Use the same cookie jar for the entire flow. No bearer or refresh token is returned.

For React on another LAN IP over HTTP, use the frontend development proxy for **both** `/api` and `/sanctum`; call relative URLs. Direct HTTP requests between different IPs have cross-site cookie restrictions that CORS alone cannot resolve. Production should use HTTPS and a shared site, such as app.example.com and api.example.com. The browser supplies Origin automatically; do not set it in React. Apidog must simulate an allowlisted frontend Origin. Sanctum stateful domains and CORS allowed origins must match the actual browser frontend.

Example React client using the existing Axios dependency:

```js
const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' }
});
await api.get('/sanctum/csrf-cookie');
```

Vite proxy targets the backend for `/api` and `/sanctum`. Use `changeOrigin: false` so Laravel receives the frontend host/origin. Keep the session cookie host-only for this proxy setup. Do not mix direct backend calls with proxied calls. Refresh the CSRF cookie after logout before a new login.

## 2. Find an advertisement

- `GET /api/v1/public/advertisements?page=1&per_page=25` — open, active, published advertisements; per_page maximum 100; paginated data/links/meta.
- `GET /api/v1/public/advertisements/{advertisement_id}` — details, instructions, eligibility and dropdown options.

Use the encrypted `data.id` from the API. Do not send numeric IDs. An encrypted ID is opaque and can change across responses; do not decode it or compare ciphertext to establish entity equality.

Advertisement options include `posts`, `qualifications`, `experiences`, `age_fee_rules`, `castes`, `caste_communities`, and `issuing_authorities`. Qualification and experience option IDs belong to this advertisement, rather than the underlying master table.

## 3. Request registration email OTP

`POST /api/v1/candidate/auth/request-registration-otp`

```json
{
  "advertisement_id": "<encrypted advertisement ID>",
  "name": "Candidate Name",
  "email": "candidate@example.com",
  "mobile": "9876543210"
}
```

Response **202**:

```json
{"data":{"challenge_id":"<UUID>","expires_in":300,"message":"If registration is available, a verification code will be sent to your email."}}
```

An existing mobile OR email under this advertisement cannot register again. The response stays generic; the OTP is never returned in JSON. Requesting a new code supersedes the prior challenge for that identity. Keep the browser session used to request the code.

## 4. Register and sign in

`POST /api/v1/candidate/auth/register`

```json
{"challenge_id":"<UUID>","otp":"123456","password":"ExamplePass123!","password_confirmation":"ExamplePass123!"}
```

Password: at least 12 characters including letters and numbers, maximum 72 bytes. Example code is illustrative; use the emailed code.

Response **201**:

```json
{"data":{"id":"<encrypted candidate ID>","application_number":"<generated number>","name":"Candidate Name","mobile":"9876543210","email":"candidate@example.com","advertisement_id":"<encrypted ID>"},"message":"Candidate registration successful."}
```

Registration creates the draft and authenticates the candidate. No separate create-application call is necessary.

Returning user: `POST /api/v1/candidate/auth/login`

```json
{"advertisement_id":"<encrypted advertisement ID>","mobile":"9876543210","password":"ExamplePass123!"}
```

Response **200**: `data` profile with the same fields. Login is rate limited. `GET /api/v1/candidate/auth/me` restores the signed-in profile after a page refresh. `POST /api/v1/candidate/auth/logout` returns `{"message":"Logout successful."}`.

Candidate authentication is separate from staff authentication. A staff session cannot edit a candidate application. Disabled or cancelled candidates cannot access the application editor.

## 5. Load the saved draft and options

- `GET /api/v1/candidate/application`
- `GET /api/v1/candidate/application/form-options`

Draft response **200**:

```json
{"data":{"application_number":"<number>","version":1,"state":"draft","details":[]}}
```

On a fresh draft, details is an empty JSON array; after saving it becomes an object keyed by section name. Save any section while incomplete and resume later. Each successful save/upload/delete increments `version`. Always replace the frontend's version with the returned value. Serialize saves and uploads; stale versions return **409**. Reload the draft and reconcile user changes before retrying.

## 6. Upload supporting documents

`POST /api/v1/candidate/application/documents` — multipart/form-data:

| Field | Value |
|---|---|
| version | latest integer version |
| purpose | photo / signature / address / dob / caste / pwd / exempted / exservice / ews / age-relaxation / qualification / experience / council |
| file | actual file |

Photo/signature: JPG or PNG, maximum 2 MB. Other purposes: PDF/JPG/PNG, maximum 5 MB. Do not manually set a multipart Content-Type boundary in Axios.

Response **201**:

```json
{"data":{"id":"<encrypted document ID>","purpose":"dob","version":2},"message":"Document uploaded successfully."}
```

Store the returned ID in the corresponding form field. Documents remain private and ownership/purpose are checked.

- `GET /api/v1/candidate/application/documents/{document_id}` — authenticated attachment download.
- `DELETE /api/v1/candidate/application/documents/{document_id}` with `{"version":2}` — draft only; response returns the incremented version. Replace any deleted document reference in the draft before submission.

## 7. Save sections

Every section uses `PATCH /api/v1/candidate/application/{section}` with:

```json
{"version":3,"data":{"<field>":"<value>"}}
```

Response **200**:

```json
{"data":{"version":4,"state":"draft","details":{"<section>":{"<field>":"<value>"}}},"message":"Application section saved successfully."}
```

Unsent fields within a section remain saved. An `items` array replaces the whole saved list; send all rows to retain them. Send an empty array to clear a list. Unknown form fields are rejected. Below are complete section examples; use the latest version for each request.

### post

```json
{"version":4,"data":{"post_id":"<options.posts[].id>"}}
```

### personal-details

```json
{
  "version":5,
  "data":{
    "dob":"1990-01-01","gender":"Female","marital_status":"Unmarried",
    "father_name":"Father Name","mother_name":"Mother Name",
    "present_address":"Present address","permanent_address":"Permanent address",
    "present_pincode":"700001","permanent_pincode":"700001","read_write_bengali":"Yes",
    "photo_document_id":"<photo upload ID>","signature_document_id":"<signature upload ID>",
    "address_document_id":"<address upload ID>","dob_document_id":"<dob upload ID>",
    "present_state_id":"<encrypted state ID>","present_district_id":"<encrypted district ID>",
    "present_subdivision_id":"<encrypted subdivision ID>","present_block_municipality_id":"<encrypted block ID>",
    "present_police_station_id":"<encrypted police station ID>","present_post_office":"Post office","present_ward_gp":"Ward",
    "council_name":null,"council_number":null,"council_document_id":null
  }
}
```

Gender: Male/Female/Other; marital status: Married/Unmarried. The selected post can impose a narrower restriction. Professional council fields are required at final submission if the advertisement requires registration. Optional permanent location fields use `permanent_` instead of `present_` with the same suffixes. Present address maximum 400 characters; permanent address maximum 250.

Location dropdown: `GET /api/v1/candidate/application/locations` returns active states. Supply encrypted query parameters `state_id`, `district_id`, `subdivision_id` to load districts, subdivisions/police stations, and blocks/municipalities respectively. Response has `data.states`, `districts`, `subdivisions`, `blocks_municipalities`, `police_stations`. Save validation checks the selected parent hierarchy.

### category-relaxations

```json
{
  "version":6,
  "data":{
    "caste_id":"<options.castes[].id>","caste_community_id":null,
    "caste_certificate_number":null,"caste_certificate_date":null,"issuing_authority_id":null,"caste_document_id":null,
    "pwd":"No","pwd_percent":null,"pwd_document_id":null,
    "exempted":"No","exempted_document_id":null,"exservice":"No","exservice_document_id":null,
    "ews":"No","ews_document_id":null,"age_relaxations":[]
  }
}
```

Reserved categories require certificate number/date, issuing authority and caste evidence at final submission. A Yes claim requires its corresponding document. PWD percentage must meet the advertisement requirement. Additional relaxation claims use:

```json
{"age_relaxations":[{"section_id":"<eligible category-8 age_fee_rules.section_id>","document_id":"<age-relaxation document ID>","reason":"Supporting explanation"}]}
```

Age relaxation and fee are calculated by the backend from advertisement rules and the submitted claims; frontend cannot set payment or approval status.

### qualifications

```json
{"version":7,"data":{"items":[{"option_id":"<options.qualifications[].id>","board":"University Name","full_marks":1000,"marks_obtained":800,"issue_date":"2020-01-01","pursuing":"No","attempts":1,"document_id":"<qualification upload ID>"}]}}
```

The option determines Essential/Desirable. Use each qualification option at most once. Pursuing is allowed only when enabled for that option. Ordered AND/OR/END essential rules are checked at final submission.

### experiences

```json
{"version":8,"data":{"items":[{"option_id":"<options.experiences[].id>","organization":"Employer Name","years":1,"months":6,"document_id":"<experience upload ID>"}]}}
```

Months: 0–11, years: 0–80. Multiple employment rows can refer to the same experience option; their claimed months are added. Essential minimum months and ordered AND/OR/END rules are enforced. Claims require supporting documents and remain subject to staff verification. If there is no experience to claim, send `{"version":8,"data":{"items":[]}}`.

## 8. Preview and correct errors

`GET /api/v1/candidate/application/preview`

```json
{"data":{"application_number":"<number>","version":9,"state":"draft","details":{},"errors":[],"can_submit":true,"fee":"200.00","payment_gateway_available":false}}
```

The actual details contains every saved section. Incomplete previews still return 200, with `can_submit:false` and errors keyed by section/field. Empty errors is `[]`; nonempty errors is an object. `GET /api/v1/candidate/application/fee-summary` currently returns this same preview structure. A displayed fee is provisional until final validation/submission.

## 9. Submit

`POST /api/v1/candidate/application/submit`

```json
{"version":9}
```

Response **200**, example for a payable application:

```json
{"data":{"application_number":"<number>","version":10,"state":"payment_pending","fee":"200.00","submitted_at":"<ISO timestamp>","payment_gateway_available":false},"message":"Application submitted. Payment is pending; the gateway is not configured yet."}
```

For fee zero, state is `complete` and message is `Application submitted successfully.` Submission is transactional: it revalidates eligibility/documents, calculates the fee, writes the existing candidate scrutiny tables, and locks editing. Retrying an already-submitted application returns its current submission without duplicating rows. Deadlines are evaluated in Asia/Kolkata and enforced by the server.

## 10. Status and receipt

- `GET /api/v1/candidate/application/status` — state, version, can_edit, fee, submitted_at, payment_complete.
- `GET /api/v1/candidate/application/receipt` — JSON application summary only when state is complete; frontend can render/print it. This is not a generated PDF endpoint.
- `POST /api/v1/candidate/application/payments` — **503** for payment_pending: integration deferred, no transaction initiated. Do not show a functional Pay button yet.

State flow: `draft → complete` (fee zero) or `draft → payment_pending` (fee payable). A payable application cannot become complete until a future verified payment integration is implemented.

## Errors and current scope

| Status | Frontend handling |
|---|---|
| 400 | Stateful browser session missing; check Origin/Sanctum/proxy setup |
| 401 | Not authenticated; show login |
| 403 | Disabled/cancelled/restricted access |
| 404 | Unknown encrypted entity or document not owned |
| 409 | Stale version, closed deadline, submitted draft, or legacy editor unavailable |
| 419 | Session/CSRF mismatch; refresh csrf-cookie with the same cookie jar, then retry safely |
| 422 | Show errors by field; do not discard draft |
| 429 | Rate limited; wait before retrying |
| 503 | Email service unavailable or deferred payment integration |

Old imported candidate accounts are not silently converted into editable drafts. They can use legacy status after compatible login, but editor operations return 409 until a dedicated import is implemented. Legacy SHA512 password compatibility is optional via private `CANDIDATE_LEGACY_PASSWORD_KEY`; it is not configured automatically. Successful compatible login upgrades that password hash. Password reset, candidate correction/resubmission, PDF generation and payment gateway integration are not included in this initial flow.

The migration has been applied; automated tests use an isolated SQLite database and fake emails/uploads. No real candidate registration, OTP email, payment, or existing application modification was performed during validation. A real browser/email smoke test is still required with your configured frontend and SMTP service.
