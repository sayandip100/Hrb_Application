# HRB API

Versioned JSON API for staff authentication and HR master data.

| | |
|---|---|
| **Base path** | `/api` |
| **Current version** | `/v1` |
| **Authentication** | Stateful browser session (cookies) |
| **Request/response format** | JSON |

> **Important:** This API uses a browser session, not bearer tokens. Keep the session cookies throughout the sign-in flow and send a CSRF token with every state-changing request.

## Contents

- [Quick start](#quick-start)
- [Authentication](#authentication)
- [Status check](#status-check)
- [Master data](#master-data)
- [List, filter, and paginate](#list-filter-and-paginate)
- [Create and update records](#create-and-update-records)
- [Response format](#response-format)
- [Errors](#errors)

## Quick start

1. **Start a browser session.** Request `GET /sanctum/csrf-cookie` (outside `/api`) and keep the cookies returned by the server.
2. **Request an OTP.** Call `POST /api/v1/staff/auth/request-otp` with the staff member's mobile number and role ID.
3. **Sign in.** Send the returned `challenge_id` and the six-digit OTP to `POST /api/v1/staff/auth/login`, using the same cookies.
4. **Call protected endpoints.** Keep sending the session cookies. For `POST` and `PATCH`, send the decoded `XSRF-TOKEN` cookie value in the `X-XSRF-TOKEN` header.

Send `Accept: application/json` with requests. For JSON request bodies, also send `Content-Type: application/json`. Browser clients calling across origins must send credentials and use an origin configured for stateful access.

## Authentication

### Request an OTP

`POST /api/v1/staff/auth/request-otp`

**Request**

```json
{
  "mobile": "9876543210",
  "role_id": 1
}
```

| Field | Requirements |
|---|---|
| `mobile` | Required; 10–15 digits. |
| `role_id` | Required; positive integer. |

If the staff account is eligible, a six-digit code is sent to its registered email. The response does not reveal whether the account is eligible.

**Accepted — `202`**

```json
{
  "data": {
    "challenge_id": "d33d7b16-5334-4d02-9165-40c607df40e0",
    "expires_in": 300,
    "message": "If the account is eligible, a verification code will be sent."
  }
}
```

The challenge is tied to the browser session that requested it. Use the same session cookies to verify the OTP. Challenges expire after five minutes; OTP requests are subject to a resend cooldown and rate limits.

### Verify the OTP

`POST /api/v1/staff/auth/login`

**Request**

```json
{
  "challenge_id": "d33d7b16-5334-4d02-9165-40c607df40e0",
  "otp": "123456"
}
```

`challenge_id` must be a UUID. `otp` must be six digits. On success, the server creates an authenticated staff session and regenerates the session ID.

**Success — `200`**

```json
{
  "data": {
    "id": 42,
    "name": "Alex Staff",
    "mobile": "9876543210",
    "role": {
      "id": 1,
      "name": "<role name>"
    }
  }
}
```

Use the session cookies returned by the browser for later requests.

### Session endpoints

| Method | Path | Access | Description |
|---|---|---|---|
| `GET` | `/api/v1/staff/auth/details` | Signed-in staff | Returns the current staff member. |
| `GET` | `/api/user` | Signed-in staff | Compatibility route; returns the current staff member. |
| `POST` | `/api/v1/staff/auth/logout` | Signed-in staff | Invalidates the session and regenerates the CSRF token. |

Successful logout returns:

```json
{
  "message": "Logout successful."
}
```

Requesting another OTP while already signed in returns `409`. If OTP email delivery is not configured, the request endpoint returns `503`.

## Status check

`GET /api/v1/status`

Does not require staff authentication.

```json
{
  "status": "ok",
  "service": "HRB API"
}
```

## Master data

Every endpoint in this section requires a browser session, an authenticated active staff account, and **staff role ID `1`**.

### Standard resource routes

The table below lists each resource path relative to `/api/v1/master-data`.

| Resource | Path | Available operations |
|---|---|---|
| Recruitments | `/recruitments` | List, create, lock, unlock |
| Disciplines | `/disciplines` | List, create, view, update, lock, unlock |
| Qualifications | `/qualifications` | List, create, view, update, lock, unlock |
| Experiences | `/experiences` | List, create, view, update, lock, unlock |
| Age relaxations | `/age-relaxations` | List, create, view, update, lock, unlock |
| Caste communities | `/caste-communities` | List, create, view, update, lock, unlock |
| Caste issuing authorities | `/caste-issuing-authorities` | List, create, view, update, lock, unlock |
| Subdivisions | `/subdivisions` | List, create, view, update, lock, unlock |
| Blocks and municipalities | `/block-municipalities` | List, create, view, update, lock, unlock |
| Police stations | `/police-stations` | List, create, view, update, lock, unlock |

For each path `{resource}` in the table, the standard operations are:

| Method | Path | Description |
|---|---|---|
| `GET` | `/{resource}` | List records. |
| `POST` | `/{resource}` | Create a record. |
| `GET` | `/{resource}/{id}` | View a record. |
| `PATCH` | `/{resource}/{id}` | Update a record. |
| `PATCH` | `/{resource}/{id}/lock` | Lock a record (`status: 0`). |
| `PATCH` | `/{resource}/{id}/unlock` | Unlock a record (`status: 1`). |

Recruitments do **not** support viewing or updating an individual record. There are no delete endpoints. Lock and unlock requests do not need a body; each returns the updated resource and a `message`.

### Option endpoints

Option endpoints return selectable related records as `{ "data": [{ "id": "...", "name": "..." }] }`.

| Method and path | Description |
|---|---|
| `GET /api/v1/master-data/caste-communities/caste-options` | Selectable top-level castes for caste communities. |
| `GET /api/v1/master-data/subdivisions/district-options` | Selectable districts for subdivisions. |
| `GET /api/v1/master-data/block-municipalities/district-options` | Selectable districts for blocks and municipalities. |
| `GET /api/v1/master-data/block-municipalities/subdivision-options?district_id={district_id}` | Active subdivisions belonging to the required district. |
| `GET /api/v1/master-data/police-stations/district-options` | Selectable districts for police stations. |

### Record IDs

Master-data IDs are opaque encrypted strings returned in each record's `id` field. Pass them back unchanged in URL path parameters and related `*_id` fields. Do not use database IDs.

For example, use the `id` returned by `caste-options` as `caste_id` when creating a caste community. Use the relevant district option's `id` as `district_id`.

## List, filter, and paginate

All master-data list endpoints accept the common query parameters below. Option endpoints do not use these filters.

| Parameter | Type | Default | Description |
|---|---|---:|---|
| `search` | String, up to 100 characters | — | Case-insensitive partial match on the record name. |
| `status` | `0` or `1` | — | Filter by status: `1` active/unlocked, `0` locked. |
| `page` | Integer, minimum `1` | `1` | Page number. |
| `per_page` | Integer, `1`–`100` | `25` | Number of records per page. |

Additional filters by resource:

| Resource(s) | Parameter | Description |
|---|---|---|
| Disciplines, qualifications | `recruitment_id` | Opaque recruitment ID. |
| Caste communities | `caste_id` | Opaque age-relaxation/caste ID. |
| Subdivisions, police stations | `district_id` | Opaque district ID. |
| Blocks and municipalities | `type` | `Block` or `Municipality`. |
| Blocks and municipalities | `district_id` | Opaque district ID. |
| Blocks and municipalities | `subdivision_id` | Opaque subdivision ID; use an empty/null value to find records without a subdivision. |

Paginated lists return Laravel's resource collection structure with `data`, `links`, and `meta`.

**Example: find active disciplines**

```http
GET /api/v1/master-data/disciplines?status=1&search=engineering&per_page=20&page=1
Accept: application/json
Cookie: <session cookies>
```

## Create and update records

Send request bodies as JSON. Updates use `PATCH`. For partial updates, send only the fields being changed, except where the table says a field is required.

| Resource | Create fields | Update fields |
|---|---|---|
| Recruitments | `name` — required, max 650 characters. | Not supported. |
| Disciplines | `name` — required, max 150; `recruitment_id` — optional, nullable. | `name` (max 150) and/or `recruitment_id`; use `null` to clear the recruitment. |
| Qualifications | `name` — required, max 850; `recruitment_id` — optional, nullable. | `name` (max 850) and/or `recruitment_id`; use `null` to clear the recruitment. |
| Experiences | `name` — required, max 21,844 characters. | `name` — required, max 21,844 characters. |
| Age relaxations | `name` — required, max 21,844 characters. | `name` — required, max 21,844 characters. |
| Caste communities | `name` — required, max 450; `caste_id` — required. | `name` (max 450) and/or `caste_id`. |
| Caste issuing authorities | `name` — required, max 250 characters. | `name` — required, max 250 characters. |
| Subdivisions | `name` — required, max 150; `district_id` — required. | `name` (max 150) and/or `district_id`. |
| Blocks and municipalities | `name` — required, max 100; `type` — required (`Block` or `Municipality`); `district_id` — required; `subdivision_id` — optional, nullable. | One or more of `name`, `type`, `district_id`, `subdivision_id`; use `null` for `subdivision_id` to clear it. |
| Police stations | `name` — required, max 200; `district_id` — required. | `name` (max 200) and/or `district_id`. |

Names must be unique within the resource's applicable scope. Related IDs must be valid opaque IDs for the corresponding resource. Related records may also need to be active and belong to the selected parent (for example, a subdivision must belong to the selected district).

**Example: create a discipline**

```http
POST /api/v1/master-data/disciplines
Accept: application/json
Content-Type: application/json
X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie value>
Cookie: <session cookies>

{
  "name": "Civil Engineering",
  "recruitment_id": "<opaque recruitment ID>"
}
```

Successful creation returns `201 Created`. The response contains the created record under `data` and a `message`.

## Response format

Single-record, create, update, and lock/unlock responses wrap the resource in `data`. A resource generally includes:

| Field | Description |
|---|---|
| `id` | Opaque ID; use this for later requests. |
| `name` | Display name. |
| `status` | `1` for active/unlocked; `0` for locked. |
| `is_locked` | Boolean equivalent of `status === 0`. |
| `created_at`, `created_by` | Creation timestamp and opaque staff ID. |
| `updated_at`, `updated_by` | Included for experiences, caste communities, caste issuing authorities, subdivisions, police stations, and blocks/municipalities. |

Related fields vary by resource:

| Resource(s) | Additional fields |
|---|---|
| Disciplines, qualifications | `recruitment_id`; `recruitment: { id, name }`. |
| Age relaxations | `category`. |
| Caste communities | `caste_id`; `caste: { id, name }`. |
| Subdivisions, police stations | `district_id`; `district: { id, name }`. |
| Blocks and municipalities | `type`, `district_id`, `district`, `subdivision_id`, `subdivision`. |

Timestamps use `Y-m-d H:i:s`. Optional relationships and timestamps can be `null`.

## Errors

Errors are returned as JSON. Validation errors use `422 Unprocessable Content` and include an `errors` object keyed by field.

| HTTP status | Meaning |
|---|---|
| `400 Bad Request` | Required browser session is missing. |
| `401 Unauthorized` | Request is not authenticated. |
| `403 Forbidden` | Staff access is inactive/not allowed, or the staff role cannot access master data. |
| `404 Not Found` | Requested record does not exist. |
| `409 Conflict` | OTP requested while already signed in. |
| `422 Unprocessable Content` | Invalid input, or OTP is invalid or expired. |
| `429 Too Many Requests` | Rate limit, OTP resend cooldown, or concurrent login limit reached. |
| `503 Service Unavailable` | OTP email delivery is not configured. |

Authentication and master-data responses use `Cache-Control: no-store`; clients should not cache them.
