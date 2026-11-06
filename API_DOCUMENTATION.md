# HRB API Documentation

> **Version:** 1.0  
> **Base URL:** `/api`  
> **Content-Type:** `application/json`  
> **Last Updated:** November 2026

---

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Common Headers](#common-headers)
- [Rate Limiting](#rate-limiting)
- [Error Responses](#error-responses)
- [Endpoints](#endpoints)
  - [Health Check](#1-health-check)
  - [Staff Authentication](#2-staff-authentication)
  - [Recruitments](#3-recruitments)
  - [Disciplines](#4-disciplines)
  - [Qualifications](#5-qualifications)
  - [Experiences](#6-experiences)
  - [Age Relaxations](#7-age-relaxations)
  - [Caste Communities](#8-caste-communities)
  - [Caste Issuing Authorities](#9-caste-issuing-authorities)
  - [Subdivisions](#10-subdivisions)
  - [Block Municipalities](#11-block-municipalities)
  - [Police Stations](#12-police-stations)
- [Resource Schemas](#resource-schemas)
- [Endpoint Summary](#endpoint-summary)

---

## Overview

The HRB API is a RESTful JSON API built with Laravel and PHP 8.5 on a PostgreSQL database. It serves as the backend for the Human Resource Board application, providing endpoints for staff authentication and master data management.

**Key Conventions:**

| Convention            | Detail                                                                                     |
| --------------------- | ------------------------------------------------------------------------------------------ |
| ID Format             | Encrypted opaque strings (via `PublicId`) — never expose raw database IDs                  |
| Route Parameters      | Must match the pattern `[A-Za-z0-9_-]{1,1024}`                                            |
| Timestamps            | `YYYY-MM-DD HH:MM:SS` format in `Asia/Kolkata` timezone                                   |
| Pagination            | Cursor-based pagination on all list endpoints (`page`, `per_page` query parameters)        |
| Ordering              | List endpoints return results sorted by ID descending (latest first) unless noted otherwise|
| Caching               | All responses include `Cache-Control: no-store` header                                     |
| Concurrency Control   | Write operations use PostgreSQL table-level locks (`SHARE ROW EXCLUSIVE MODE`)             |

---

## Authentication

This API uses **Laravel Sanctum** session-based SPA authentication with cookie support.

### Authentication Flow

1. **Request OTP** — Send staff mobile number and role ID to receive a verification challenge.
2. **Verify OTP** — Submit the challenge ID and 6-digit OTP to authenticate and create a session.
3. **Subsequent Requests** — The browser session cookie authenticates all further requests automatically.
4. **Logout** — Invalidate the session and regenerate the CSRF token.

> **Important:** This API uses session/cookie-based authentication, not Bearer tokens. The `EnsureStaffBrowserSession` middleware enforces that all requests originate from a valid browser session. Direct API access tools (Postman, curl) require proper session and CSRF cookie handling.

---

## Common Headers

All requests **must** include the following headers:

| Header          | Value                                      | Required | Notes                                               |
| --------------- | ------------------------------------------ | -------- | --------------------------------------------------- |
| `Accept`        | `application/json`                         | Yes      | Ensures JSON error responses                        |
| `Content-Type`  | `application/json`                         | Yes      | For POST/PATCH requests with a body                 |
| `X-XSRF-TOKEN`  | CSRF token from cookie                    | Yes*     | Required for non-GET requests (Sanctum SPA)         |
| `Referer`       | Must match the configured frontend URL     | Yes      | Validated by browser session middleware              |
| `Origin`        | Must match the configured frontend URL     | Yes      | Validated by browser session middleware              |

> **Note:** The `EnsureStaffBrowserSession` middleware validates that requests originate from the allowed frontend. For non-GET requests, a valid session cookie is also required. Direct API access without the correct `Referer`/`Origin` headers will be blocked with a `403` response.

---

## Rate Limiting

| Limiter              | Limit                  | Applied To          |
| -------------------- | ---------------------- | ------------------- |
| `staff-otp-request`  | 1 request / 2 minutes  | OTP request endpoint|
| `staff-otp-verify`   | 5 requests / 1 minute  | Login endpoint      |
| `api` (default)      | 60 requests / 1 minute | All other endpoints |

When rate-limited, the API returns a `429 Too Many Requests` response.

---

## Error Responses

All error responses follow a consistent JSON structure:

```json
{
  "message": "Human-readable error description"
}
```

### Standard HTTP Status Codes

| Code | Meaning                 | When It Occurs                                                     |
| ---- | ----------------------- | ------------------------------------------------------------------ |
| 200  | OK                      | Successful GET, PATCH                                              |
| 201  | Created                 | Successful POST (resource created)                                 |
| 202  | Accepted                | OTP request accepted                                               |
| 400  | Bad Request             | Missing browser session                                            |
| 403  | Forbidden               | Inactive account, insufficient role, or direct API access          |
| 404  | Not Found               | Resource not found or invalid encrypted ID                         |
| 409  | Conflict                | Already signed in, or attempting conflicting state change           |
| 422  | Unprocessable Entity    | Validation failure                                                 |
| 429  | Too Many Requests       | Rate limit exceeded                                                |

### Validation Error Response (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name is required."
    ]
  }
}
```

---

## Endpoints

---

### 1. Health Check

#### `GET /api/v1/status`

Returns the current status of the API service. No authentication required.

**Response `200 OK`:**

```json
{
  "status": "ok",
  "service": "HRB API"
}
```

---

### 2. Staff Authentication

**Base Path:** `/api/v1/staff/auth`  
**Middleware:** `EnsureStaffBrowserSession`

---

#### 2.1 Request OTP

```
POST /api/v1/staff/auth/request-otp
```

Initiates the OTP verification flow for staff login. Returns a challenge ID used for OTP verification.

**Rate Limit:** 1 request per 2 minutes

**Middleware:** `EnsureStaffBrowserSession`, `throttle:staff-otp-request`

**Pre-condition:** Must NOT be already signed in (returns `409` if already authenticated).

**Request Body:**

| Field     | Type    | Required | Validation                                                   |
| --------- | ------- | -------- | ------------------------------------------------------------ |
| `mobile`  | string  | Yes      | 10–15 digit number (`/^[0-9]{10,15}$/`)                      |
| `role_id` | integer | Yes      | Integer, min: 1, max: 2147483647                              |

**Prohibited Fields:** `user_id`, `email`

**Example Request:**

```json
{
  "mobile": "9876543210",
  "role_id": 1
}
```

**Response `202 Accepted`:**

```json
{
  "data": {
    "challenge_id": "b3e34bcf-a19c-4613-a4e9-0ef584489a81",
    "expires_in": 300,
    "message": "If the account is eligible, a verification code will be sent."
  }
}
```

**Error Responses:**

| Code | Message                              | Condition              |
| ---- | ------------------------------------ | ---------------------- |
| 409  | `You are already signed in.`         | Already authenticated  |
| 422  | Validation errors                    | Invalid input format   |

---

#### 2.2 Login (Verify OTP)

```
POST /api/v1/staff/auth/login
```

Verifies the OTP against the challenge and creates an authenticated session.

**Rate Limit:** 5 requests per minute

**Middleware:** `EnsureStaffBrowserSession`, `throttle:staff-otp-verify`

**Pre-condition:** Must NOT be already signed in (returns `409` if already authenticated).

**Request Body:**

| Field          | Type   | Required | Validation                      |
| -------------- | ------ | -------- | ------------------------------- |
| `challenge_id` | string | Yes      | Valid UUID                       |
| `otp`          | string | Yes      | Exactly 6 digits (`digits:6`)   |

**Prohibited Fields:** `role_id`, `user_id`

**Example Request:**

```json
{
  "challenge_id": "b3e34bcf-a19c-4613-a4e9-0ef584489a81",
  "otp": "123456"
}
```

**Response `200 OK`:**

```json
{
  "data": {
    "id": 1,
    "name": "John Doe",
    "mobile": "9876543210",
    "role": {
      "id": 1,
      "name": "Super Admin"
    }
  }
}
```

**Error Responses:**

| Code | Message                                 | Condition                    |
| ---- | --------------------------------------- | ---------------------------- |
| 409  | `You are already signed in.`            | Already authenticated        |
| 422  | Validation errors                       | Invalid/expired challenge or OTP |

---

#### 2.3 Get Authenticated User

```
GET /api/v1/staff/auth/details
```

Returns the currently authenticated staff member's details.

**Authentication:** Required  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`

> **Alias:** `GET /api/user` — identical endpoint.

**Response `200 OK`:** [StaffUserResource](#staffuserresource)

```json
{
  "data": {
    "id": 1,
    "name": "John Doe",
    "mobile": "9876543210",
    "role": {
      "id": 1,
      "name": "Super Admin"
    }
  }
}
```

---

#### 2.4 Logout

```
POST /api/v1/staff/auth/logout
```

Logs out the staff member, invalidates the session, and regenerates the CSRF token.

**Authentication:** Required  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`

**Response `200 OK`:**

```json
{
  "message": "Logout successful."
}
```

---

### 3. Recruitments

**Base Path:** `/api/v1/master-data/recruitments`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 3.1 List All Recruitments

```
GET /api/v1/master-data/recruitments
```

Returns a paginated list of recruitments, ordered by name ascending.

**Query Parameters:**

| Parameter  | Type    | Required | Validation                              | Description                                |
| ---------- | ------- | -------- | --------------------------------------- | ------------------------------------------ |
| `search`   | string  | No       | Max 100 characters                      | Case-insensitive search on recruitment name|
| `status`   | integer | No       | `0` or `1`                              | Filter by status (0=locked, 1=active)      |
| `per_page` | integer | No       | 1–100 (default: 25)                     | Number of items per page                   |
| `page`     | integer | No       | Min: 1                                  | Page number                                |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "eyJpdiI6...",
      "name": "Medical Officer Recruitment 2026",
      "status": 1,
      "is_locked": false,
      "created_at": "2026-03-10 11:20:00",
      "created_by": "eyJpdiI6..."
    }
  ],
  "links": {
    "first": "...?page=1",
    "last": "...?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 25,
    "to": 1,
    "total": 1
  }
}
```

---

#### 3.2 Create Recruitment

```
POST /api/v1/master-data/recruitments
```

**Request Body:**

| Field  | Type   | Required | Validation                                              |
| ------ | ------ | -------- | ------------------------------------------------------- |
| `name` | string | Yes      | Max 650 characters, case-insensitive unique              |

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Example Request:**

```json
{
  "name": "Medical Officer Recruitment 2026"
}
```

**Response `201 Created`:**

```json
{
  "data": {
    "id": "eyJpdiI6...",
    "name": "Medical Officer Recruitment 2026",
    "status": 1,
    "is_locked": false,
    "created_at": "2026-11-04 13:30:00",
    "created_by": "eyJpdiI6..."
  },
  "message": "Recruitment added successfully."
}
```

**Validation Errors:**

| Field  | Error                                             |
| ------ | ------------------------------------------------- |
| `name` | `A recruitment with this name already exists.`    |

---

#### 3.3 Lock Recruitment

```
PATCH /api/v1/master-data/recruitments/{recruitment}/lock
```

Sets the recruitment status to locked (0).

**Path Parameters:**

| Parameter     | Type   | Description                          |
| ------------- | ------ | ------------------------------------ |
| `recruitment` | string | Encrypted Recruitment ID             |

**Response `200 OK`:**

```json
{
  "data": {
    "id": "eyJpdiI6...",
    "name": "Medical Officer Recruitment 2026",
    "status": 0,
    "is_locked": true,
    "created_at": "2026-11-04 13:30:00",
    "created_by": "eyJpdiI6..."
  },
  "message": "Recruitment locked successfully."
}
```

---

#### 3.4 Unlock Recruitment

```
PATCH /api/v1/master-data/recruitments/{recruitment}/unlock
```

Sets the recruitment status to active (1).

**Path Parameters:**

| Parameter     | Type   | Description                          |
| ------------- | ------ | ------------------------------------ |
| `recruitment` | string | Encrypted Recruitment ID             |

**Response `200 OK`:**

```json
{
  "data": {
    "id": "eyJpdiI6...",
    "name": "Medical Officer Recruitment 2026",
    "status": 1,
    "is_locked": false,
    "created_at": "2026-11-04 13:30:00",
    "created_by": "eyJpdiI6..."
  },
  "message": "Recruitment unlocked successfully."
}
```

---

### 4. Disciplines

**Base Path:** `/api/v1/master-data/disciplines`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 4.1 List All Disciplines

```
GET /api/v1/master-data/disciplines
```

Returns a paginated list of disciplines with their recruitment relationship.

**Query Parameters:**

| Parameter        | Type    | Required | Validation                                       | Description                                  |
| ---------------- | ------- | -------- | ------------------------------------------------ | -------------------------------------------- |
| `search`         | string  | No       | Max 100 characters                               | Case-insensitive search on discipline name   |
| `status`         | integer | No       | `0` or `1`                                       | Filter by status (0=locked, 1=active)        |
| `recruitment_id` | string  | No       | Encrypted ID, validated by `EncryptedRecruitmentId` | Filter by parent recruitment              |
| `per_page`       | integer | No       | 1–100 (default: 25)                              | Number of items per page                     |
| `page`           | integer | No       | Min: 1                                           | Page number                                  |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "eyJpdiI6...",
      "name": "General Medicine",
      "recruitment_id": "eyJpdiI6...",
      "recruitment": {
        "id": "eyJpdiI6...",
        "name": "Medical Officer Recruitment 2026"
      },
      "status": 1,
      "is_locked": false,
      "created_at": "2026-11-04 13:30:00",
      "created_by": "eyJpdiI6..."
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

---

#### 4.2 Get Discipline

```
GET /api/v1/master-data/disciplines/{discipline}
```

**Path Parameters:**

| Parameter    | Type   | Description              |
| ------------ | ------ | ------------------------ |
| `discipline` | string | Encrypted Discipline ID  |

**Response `200 OK`:** [DisciplineResource](#disciplineresource)

```json
{
  "data": {
    "id": "eyJpdiI6...",
    "name": "General Medicine",
    "recruitment_id": "eyJpdiI6...",
    "recruitment": {
      "id": "eyJpdiI6...",
      "name": "Medical Officer Recruitment 2026"
    },
    "status": 1,
    "is_locked": false,
    "created_at": "2026-11-04 13:30:00",
    "created_by": "eyJpdiI6..."
  }
}
```

---

#### 4.3 Create Discipline

```
POST /api/v1/master-data/disciplines
```

**Request Body:**

| Field            | Type   | Required | Validation                                                              |
| ---------------- | ------ | -------- | ----------------------------------------------------------------------- |
| `name`           | string | Yes      | Max 150 characters                                                      |
| `recruitment_id` | string | No       | Encrypted ID, must reference an existing unlocked recruitment           |

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Example Request:**

```json
{
  "name": "General Medicine",
  "recruitment_id": "eyJpdiI6..."
}
```

**Response `201 Created`:**

```json
{
  "data": {
    "id": "eyJpdiI6...",
    "name": "General Medicine",
    "recruitment_id": "eyJpdiI6...",
    "recruitment": {
      "id": "eyJpdiI6...",
      "name": "Medical Officer Recruitment 2026"
    },
    "status": 1,
    "is_locked": false,
    "created_at": "2026-11-04 13:30:00",
    "created_by": "eyJpdiI6..."
  },
  "message": "Discipline added successfully."
}
```

**Validation Errors:**

| Field            | Error                                             |
| ---------------- | ------------------------------------------------- |
| `recruitment_id` | `Select an existing, unlocked recruitment.`       |

---

#### 4.4 Update Discipline

```
PATCH /api/v1/master-data/disciplines/{discipline}
```

At least one of `name` or `recruitment_id` must be provided.

**Request Body (all fields optional, at least one required):**

| Field            | Type   | Required | Validation                                                              |
| ---------------- | ------ | -------- | ----------------------------------------------------------------------- |
| `name`           | string | No       | Max 150 characters                                                      |
| `recruitment_id` | string | No       | Encrypted ID, must reference an existing unlocked recruitment           |

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Discipline updated successfully."
}
```

**Validation Errors:**

| Error                                             |
| ------------------------------------------------- |
| `Provide a name or recruitment_id to update.`     |
| `Select an existing, unlocked recruitment.`       |

---

#### 4.5 Lock Discipline

```
PATCH /api/v1/master-data/disciplines/{discipline}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Discipline locked successfully."
}
```

---

#### 4.6 Unlock Discipline

```
PATCH /api/v1/master-data/disciplines/{discipline}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Discipline unlocked successfully."
}
```

---

### 5. Qualifications

**Base Path:** `/api/v1/master-data/qualifications`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 5.1 List All Qualifications

```
GET /api/v1/master-data/qualifications
```

Returns a paginated list of qualifications with their recruitment relationship.

**Query Parameters:**

| Parameter        | Type    | Required | Validation                                          | Description                                    |
| ---------------- | ------- | -------- | --------------------------------------------------- | ---------------------------------------------- |
| `search`         | string  | No       | Max 100 characters                                  | Case-insensitive search on qualification name  |
| `status`         | integer | No       | `0` or `1`                                          | Filter by status (0=locked, 1=active)          |
| `recruitment_id` | string  | No       | Encrypted ID, validated by `EncryptedRecruitmentId` | Filter by parent recruitment                   |
| `per_page`       | integer | No       | 1–100 (default: 25)                                 | Number of items per page                       |
| `page`           | integer | No       | Min: 1                                              | Page number                                    |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "eyJpdiI6...",
      "name": "MBBS",
      "recruitment_id": "eyJpdiI6...",
      "recruitment": {
        "id": "eyJpdiI6...",
        "name": "Medical Officer Recruitment 2026"
      },
      "status": 1,
      "is_locked": false,
      "created_at": "2026-11-04 13:30:00",
      "created_by": "eyJpdiI6..."
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

---

#### 5.2 Get Qualification

```
GET /api/v1/master-data/qualifications/{qualification}
```

**Path Parameters:**

| Parameter       | Type   | Description                |
| --------------- | ------ | -------------------------- |
| `qualification` | string | Encrypted Qualification ID |

**Response `200 OK`:** [QualificationResource](#qualificationresource)

---

#### 5.3 Create Qualification

```
POST /api/v1/master-data/qualifications
```

**Request Body:**

| Field            | Type   | Required | Validation                                                              |
| ---------------- | ------ | -------- | ----------------------------------------------------------------------- |
| `name`           | string | Yes      | Max 850 characters, case-insensitive unique                              |
| `recruitment_id` | string | No       | Encrypted ID, must reference an existing unlocked recruitment           |

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Example Request:**

```json
{
  "name": "MBBS",
  "recruitment_id": "eyJpdiI6..."
}
```

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Qualification added successfully."
}
```

**Validation Errors:**

| Field            | Error                                              |
| ---------------- | -------------------------------------------------- |
| `name`           | `A qualification with this name already exists.`   |
| `recruitment_id` | `Select an existing, unlocked recruitment.`        |

---

#### 5.4 Update Qualification

```
PATCH /api/v1/master-data/qualifications/{qualification}
```

At least one of `name` or `recruitment_id` must be provided.

**Request Body (all fields optional, at least one required):**

| Field            | Type   | Required | Validation                                                              |
| ---------------- | ------ | -------- | ----------------------------------------------------------------------- |
| `name`           | string | No       | Max 850 characters, case-insensitive unique (excluding current)         |
| `recruitment_id` | string | No       | Encrypted ID, must reference an existing unlocked recruitment           |

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Qualification updated successfully."
}
```

---

#### 5.5 Lock Qualification

```
PATCH /api/v1/master-data/qualifications/{qualification}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Qualification locked successfully."
}
```

---

#### 5.6 Unlock Qualification

```
PATCH /api/v1/master-data/qualifications/{qualification}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Qualification unlocked successfully."
}
```

---

### 6. Experiences

**Base Path:** `/api/v1/master-data/experiences`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 6.1 List All Experiences

```
GET /api/v1/master-data/experiences
```

Returns a paginated list of experiences.

**Query Parameters:**

| Parameter        | Type    | Required | Validation                                          | Description                                |
| ---------------- | ------- | -------- | --------------------------------------------------- | ------------------------------------------ |
| `search`         | string  | No       | Max 100 characters                                  | Case-insensitive search on experience name |
| `status`         | integer | No       | `0` or `1`                                          | Filter by status                           |
| `per_page`       | integer | No       | 1–100 (default: 25)                                 | Number of items per page                   |
| `page`           | integer | No       | Min: 1                                              | Page number                                |

**Response `200 OK`:** Paginated collection of [ExperienceResource](#experienceresource)

---

#### 6.2 Get Experience

```
GET /api/v1/master-data/experiences/{experience}
```

**Response `200 OK`:** [ExperienceResource](#experienceresource)

---

#### 6.3 Create Experience

```
POST /api/v1/master-data/experiences
```

**Request Body:**

| Field            | Type   | Required | Validation                                                              |
| ---------------- | ------ | -------- | ----------------------------------------------------------------------- |
| `name`           | string | Yes      | Max length, case-insensitive unique                                      |
| `recruitment_id` | string | No       | Encrypted ID, must reference an existing unlocked recruitment           |

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Experience added successfully."
}
```

---

#### 6.4 Update Experience

```
PATCH /api/v1/master-data/experiences/{experience}
```

At least one updatable field must be provided.

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Experience updated successfully."
}
```

---

#### 6.5 Lock Experience

```
PATCH /api/v1/master-data/experiences/{experience}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Experience locked successfully."
}
```

---

#### 6.6 Unlock Experience

```
PATCH /api/v1/master-data/experiences/{experience}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Experience unlocked successfully."
}
```

---

### 7. Age Relaxations

**Base Path:** `/api/v1/master-data/age-relaxations`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 7.1 List All Age Relaxations

```
GET /api/v1/master-data/age-relaxations
```

Returns a paginated list of age relaxations.

**Query Parameters:**

| Parameter  | Type    | Required | Validation           | Description                                    |
| ---------- | ------- | -------- | -------------------- | ---------------------------------------------- |
| `search`   | string  | No       | Max 100 characters   | Case-insensitive search                        |
| `status`   | integer | No       | `0` or `1`           | Filter by status                               |
| `per_page` | integer | No       | 1–100 (default: 25)  | Number of items per page                       |
| `page`     | integer | No       | Min: 1               | Page number                                    |

**Response `200 OK`:** Paginated collection of [AgeRelaxationResource](#agerelaxationresource)

---

#### 7.2 Get Age Relaxation

```
GET /api/v1/master-data/age-relaxations/{ageRelaxation}
```

**Response `200 OK`:** [AgeRelaxationResource](#agerelaxationresource)

---

#### 7.3 Create Age Relaxation

```
POST /api/v1/master-data/age-relaxations
```

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Age Relaxation added successfully."
}
```

---

#### 7.4 Update Age Relaxation

```
PATCH /api/v1/master-data/age-relaxations/{ageRelaxation}
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Age Relaxation updated successfully."
}
```

---

#### 7.5 Lock Age Relaxation

```
PATCH /api/v1/master-data/age-relaxations/{ageRelaxation}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Age Relaxation locked successfully."
}
```

---

#### 7.6 Unlock Age Relaxation

```
PATCH /api/v1/master-data/age-relaxations/{ageRelaxation}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Age Relaxation unlocked successfully."
}
```

---

### 8. Caste Communities

**Base Path:** `/api/v1/master-data/caste-communities`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 8.1 List All Caste Communities

```
GET /api/v1/master-data/caste-communities
```

Returns a paginated list of caste communities.

**Query Parameters:**

| Parameter  | Type    | Required | Validation           | Description                                      |
| ---------- | ------- | -------- | -------------------- | ------------------------------------------------ |
| `search`   | string  | No       | Max 100 characters   | Case-insensitive search on caste community name  |
| `status`   | integer | No       | `0` or `1`           | Filter by status                                 |
| `per_page` | integer | No       | 1–100 (default: 25)  | Number of items per page                         |
| `page`     | integer | No       | Min: 1               | Page number                                      |

**Response `200 OK`:** Paginated collection of [CasteCommunityResource](#castecommunityresource)

---

#### 8.2 Get Caste Options

```
GET /api/v1/master-data/caste-communities/caste-options
```

Returns available caste category options for dropdown menus.

**Response `200 OK`:**

```json
{
  "data": [
    { "label": "General", "value": "general" },
    { "label": "OBC-A", "value": "obc_a" },
    { "label": "OBC-B", "value": "obc_b" },
    { "label": "SC", "value": "sc" },
    { "label": "ST", "value": "st" }
  ]
}
```

---

#### 8.3 Create Caste Community

```
POST /api/v1/master-data/caste-communities
```

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Community added successfully."
}
```

---

#### 8.4 Get Caste Community

```
GET /api/v1/master-data/caste-communities/{casteCommunity}
```

**Response `200 OK`:** [CasteCommunityResource](#castecommunityresource)

---

#### 8.5 Update Caste Community

```
PATCH /api/v1/master-data/caste-communities/{casteCommunity}
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Community updated successfully."
}
```

---

#### 8.6 Lock Caste Community

```
PATCH /api/v1/master-data/caste-communities/{casteCommunity}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Community locked successfully."
}
```

---

#### 8.7 Unlock Caste Community

```
PATCH /api/v1/master-data/caste-communities/{casteCommunity}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Community unlocked successfully."
}
```

---

### 9. Caste Issuing Authorities

**Base Path:** `/api/v1/master-data/caste-issuing-authorities`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 9.1 List All Caste Issuing Authorities

```
GET /api/v1/master-data/caste-issuing-authorities
```

Returns a paginated list of caste issuing authorities.

**Query Parameters:**

| Parameter  | Type    | Required | Validation           | Description                                |
| ---------- | ------- | -------- | -------------------- | ------------------------------------------ |
| `search`   | string  | No       | Max 100 characters   | Case-insensitive search                    |
| `status`   | integer | No       | `0` or `1`           | Filter by status                           |
| `per_page` | integer | No       | 1–100 (default: 25)  | Number of items per page                   |
| `page`     | integer | No       | Min: 1               | Page number                                |

**Response `200 OK`:** Paginated collection of [CasteIssuingAuthorityResource](#casteissuingauthorityresource)

---

#### 9.2 Create Caste Issuing Authority

```
POST /api/v1/master-data/caste-issuing-authorities
```

**Prohibited Fields:** `id`, `status`, `created_by`, `created_at`

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Issuing Authority added successfully."
}
```

---

#### 9.3 Get Caste Issuing Authority

```
GET /api/v1/master-data/caste-issuing-authorities/{casteIssuingAuthority}
```

**Response `200 OK`:** [CasteIssuingAuthorityResource](#casteissuingauthorityresource)

---

#### 9.4 Update Caste Issuing Authority

```
PATCH /api/v1/master-data/caste-issuing-authorities/{casteIssuingAuthority}
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Issuing Authority updated successfully."
}
```

---

#### 9.5 Lock Caste Issuing Authority

```
PATCH /api/v1/master-data/caste-issuing-authorities/{casteIssuingAuthority}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Issuing Authority locked successfully."
}
```

---

#### 9.6 Unlock Caste Issuing Authority

```
PATCH /api/v1/master-data/caste-issuing-authorities/{casteIssuingAuthority}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Caste Issuing Authority unlocked successfully."
}
```

---

### 10. Subdivisions

**Base Path:** `/api/v1/master-data/subdivisions`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 10.1 List All Subdivisions

```
GET /api/v1/master-data/subdivisions
```

Returns a paginated list of subdivisions with their district relationship.

**Query Parameters:**

| Parameter     | Type    | Required | Validation                                       | Description                                  |
| ------------- | ------- | -------- | ------------------------------------------------ | -------------------------------------------- |
| `search`      | string  | No       | Max 100 characters                               | Case-insensitive search on subdivision name  |
| `status`      | integer | No       | `0` or `1`                                       | Filter by status (0=locked, 1=active)        |
| `district_id` | string  | No       | Encrypted ID, validated by `EncryptedDistrictId` | Filter by parent district                    |
| `per_page`    | integer | No       | 1–100 (default: 25)                              | Number of items per page                     |
| `page`        | integer | No       | Min: 1                                           | Page number                                  |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "eyJpdiI6...",
      "name": "Kolkata Sadar",
      "district_id": "eyJpdiI6...",
      "district": {
        "id": "eyJpdiI6...",
        "name": "Kolkata"
      },
      "status": 1,
      "is_locked": false,
      "created_at": "2026-01-01 10:00:00",
      "created_by": "eyJpdiI6...",
      "updated_at": null,
      "updated_by": null
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

---

#### 10.2 Get District Options

```
GET /api/v1/master-data/subdivisions/district-options
```

Returns available district options for dropdown menus. Only selectable (active) districts are returned.

**Response `200 OK`:**

```json
{
  "data": [
    { "id": "eyJpdiI6...", "name": "Bankura" },
    { "id": "eyJpdiI6...", "name": "Birbhum" },
    { "id": "eyJpdiI6...", "name": "Kolkata" }
  ]
}
```

---

#### 10.3 Create Subdivision

```
POST /api/v1/master-data/subdivisions
```

**Request Body:**

| Field         | Type   | Required | Validation                                                                         |
| ------------- | ------ | -------- | ---------------------------------------------------------------------------------- |
| `name`        | string | Yes      | Max 150 characters, case-insensitive unique within district                         |
| `district_id` | string | Yes      | Encrypted ID, must reference an existing unlocked district                         |

**Prohibited Fields:** `id`, `status`, `code`, `created_by`, `created_at`, `updated_by`, `updated_at`

**Example Request:**

```json
{
  "name": "Kolkata Sadar",
  "district_id": "eyJpdiI6..."
}
```

**Response `201 Created`:**

```json
{
  "data": {
    "id": "eyJpdiI6...",
    "name": "Kolkata Sadar",
    "district_id": "eyJpdiI6...",
    "district": {
      "id": "eyJpdiI6...",
      "name": "Kolkata"
    },
    "status": 1,
    "is_locked": false,
    "created_at": "2026-11-04 13:30:00",
    "created_by": "eyJpdiI6...",
    "updated_at": null,
    "updated_by": null
  },
  "message": "Sub-Division added successfully."
}
```

---

#### 10.4 Get Subdivision

```
GET /api/v1/master-data/subdivisions/{subdivision}
```

**Response `200 OK`:** [SubdivisionResource](#subdivisionresource)

---

#### 10.5 Update Subdivision

```
PATCH /api/v1/master-data/subdivisions/{subdivision}
```

At least one of `name` or `district_id` must be provided.

**Request Body (all fields optional, at least one required):**

| Field         | Type   | Required | Validation                                                                         |
| ------------- | ------ | -------- | ---------------------------------------------------------------------------------- |
| `name`        | string | No       | Max 150 characters, case-insensitive unique within district (excluding current)     |
| `district_id` | string | No       | Encrypted ID, must reference an existing unlocked district                         |

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Sub-Division updated successfully."
}
```

---

#### 10.6 Lock Subdivision

```
PATCH /api/v1/master-data/subdivisions/{subdivision}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Sub-Division locked successfully."
}
```

---

#### 10.7 Unlock Subdivision

```
PATCH /api/v1/master-data/subdivisions/{subdivision}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Sub-Division unlocked successfully."
}
```

---

### 11. Block Municipalities

**Base Path:** `/api/v1/master-data/block-municipalities`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 11.1 List All Block Municipalities

```
GET /api/v1/master-data/block-municipalities
```

Returns a paginated list of block/municipalities with their subdivision and district relationships.

**Query Parameters:**

| Parameter        | Type    | Required | Validation                                          | Description                                     |
| ---------------- | ------- | -------- | --------------------------------------------------- | ----------------------------------------------- |
| `search`         | string  | No       | Max 100 characters                                  | Case-insensitive search on name                 |
| `status`         | integer | No       | `0` or `1`                                          | Filter by status (0=locked, 1=active)           |
| `type`           | string  | No       | `Block` or `Municipality`                           | Filter by type                                  |
| `district_id`    | string  | No       | Encrypted ID, validated by `EncryptedDistrictId`    | Filter by district                              |
| `subdivision_id` | string  | No       | Encrypted ID, validated by `EncryptedSubdivisionId` | Filter by subdivision (null returns unassigned)  |
| `per_page`       | integer | No       | 1–100 (default: 25)                                 | Number of items per page                        |
| `page`           | integer | No       | Min: 1                                              | Page number                                     |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "eyJpdiI6...",
      "name": "Kolkata Municipal Corporation",
      "type": "Municipality",
      "district_id": "eyJpdiI6...",
      "district": {
        "id": "eyJpdiI6...",
        "name": "Kolkata"
      },
      "subdivision_id": "eyJpdiI6...",
      "subdivision": {
        "id": "eyJpdiI6...",
        "name": "Kolkata Sadar"
      },
      "status": 1,
      "is_locked": false,
      "created_at": "2026-01-01 10:00:00",
      "created_by": "eyJpdiI6...",
      "updated_at": null,
      "updated_by": null
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

---

#### 11.2 Get District Options

```
GET /api/v1/master-data/block-municipalities/district-options
```

Returns available active district options for dropdown menus.

**Response `200 OK`:**

```json
{
  "data": [
    { "id": "eyJpdiI6...", "name": "Bankura" },
    { "id": "eyJpdiI6...", "name": "Kolkata" }
  ]
}
```

---

#### 11.3 Get Subdivision Options

```
GET /api/v1/master-data/block-municipalities/subdivision-options
```

Returns active subdivisions filtered by district, for cascading dropdown menus.

**Query Parameters:**

| Parameter     | Type   | Required | Validation                                       | Description          |
| ------------- | ------ | -------- | ------------------------------------------------ | -------------------- |
| `district_id` | string | Yes      | Encrypted ID, validated by `EncryptedDistrictId` | District to filter by|

**Example Request:**

```
GET /api/v1/master-data/block-municipalities/subdivision-options?district_id=eyJpdiI6...
```

**Response `200 OK`:**

```json
{
  "data": [
    { "id": "eyJpdiI6...", "name": "Kolkata Sadar" }
  ]
}
```

---

#### 11.4 Create Block Municipality

```
POST /api/v1/master-data/block-municipalities
```

**Request Body:**

| Field            | Type   | Required | Validation                                                                           |
| ---------------- | ------ | -------- | ------------------------------------------------------------------------------------ |
| `name`           | string | Yes      | Max 100 characters, case-insensitive unique within (district, subdivision)            |
| `type`           | string | Yes      | `Block` or `Municipality`                                                            |
| `district_id`    | string | Yes      | Encrypted ID, must reference an existing active district                             |
| `subdivision_id` | string | No       | Encrypted ID, must reference an active subdivision belonging to the selected district|

**Prohibited Fields:** `id`, `status`, `code`, `created_by`, `created_at`, `updated_by`, `updated_at`

**Example Request:**

```json
{
  "name": "Kolkata Municipal Corporation",
  "type": "Municipality",
  "district_id": "eyJpdiI6...",
  "subdivision_id": "eyJpdiI6..."
}
```

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Block/Municipality added successfully."
}
```

---

#### 11.5 Get Block Municipality

```
GET /api/v1/master-data/block-municipalities/{blockMunicipality}
```

**Response `200 OK`:** [BlockMunicipalityResource](#blockmunicipalityresource)

---

#### 11.6 Update Block Municipality

```
PATCH /api/v1/master-data/block-municipalities/{blockMunicipality}
```

At least one of `name`, `type`, `district_id`, or `subdivision_id` must be provided.

**Request Body (all fields optional, at least one required):**

| Field            | Type   | Required | Validation                                                                           |
| ---------------- | ------ | -------- | ------------------------------------------------------------------------------------ |
| `name`           | string | No       | Max 100 characters, case-insensitive unique (excluding current)                      |
| `type`           | string | No       | `Block` or `Municipality`                                                            |
| `district_id`    | string | No       | Encrypted ID, must reference an existing active district                             |
| `subdivision_id` | string | No       | Encrypted ID, must reference an active subdivision belonging to the selected district|

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Block/Municipality updated successfully."
}
```

---

#### 11.7 Lock Block Municipality

```
PATCH /api/v1/master-data/block-municipalities/{blockMunicipality}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Block/Municipality locked successfully."
}
```

---

#### 11.8 Unlock Block Municipality

```
PATCH /api/v1/master-data/block-municipalities/{blockMunicipality}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Block/Municipality unlocked successfully."
}
```

---

### 12. Police Stations

**Base Path:** `/api/v1/master-data/police-stations`  
**Authentication:** Required  
**Authorization:** Staff Role `1` (Super Admin) only  
**Middleware:** `EnsureStaffBrowserSession`, `auth:sanctum`, `EnsureActiveStaff`, `RequireStaffRole:1`

---

#### 12.1 List All Police Stations

```
GET /api/v1/master-data/police-stations
```

Returns a paginated list of police stations with their district relationship.

**Query Parameters:**

| Parameter     | Type    | Required | Validation                                       | Description                                    |
| ------------- | ------- | -------- | ------------------------------------------------ | ---------------------------------------------- |
| `search`      | string  | No       | Max 100 characters                               | Case-insensitive search on police station name |
| `status`      | integer | No       | `0` or `1`                                       | Filter by status (0=locked, 1=active)          |
| `district_id` | string  | No       | Encrypted ID, validated by `EncryptedDistrictId` | Filter by district                             |
| `per_page`    | integer | No       | 1–100 (default: 25)                              | Number of items per page                       |
| `page`        | integer | No       | Min: 1                                           | Page number                                    |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "eyJpdiI6...",
      "name": "Lalbazar Police Station",
      "district_id": "eyJpdiI6...",
      "district": {
        "id": "eyJpdiI6...",
        "name": "Kolkata"
      },
      "status": 1,
      "is_locked": false,
      "created_at": "2026-01-01 10:00:00",
      "created_by": "eyJpdiI6...",
      "updated_at": null,
      "updated_by": null
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

---

#### 12.2 Get District Options

```
GET /api/v1/master-data/police-stations/district-options
```

Returns available active district options for dropdown menus.

**Response `200 OK`:**

```json
{
  "data": [
    { "id": "eyJpdiI6...", "name": "Bankura" },
    { "id": "eyJpdiI6...", "name": "Kolkata" }
  ]
}
```

---

#### 12.3 Create Police Station

```
POST /api/v1/master-data/police-stations
```

**Request Body:**

| Field         | Type   | Required | Validation                                                                       |
| ------------- | ------ | -------- | -------------------------------------------------------------------------------- |
| `name`        | string | Yes      | Max 200 characters, case-insensitive unique within district                       |
| `district_id` | string | Yes      | Encrypted ID, must reference an existing active district                         |

**Prohibited Fields:** `id`, `status`, `code`, `created_by`, `created_at`, `updated_by`, `updated_at`

**Example Request:**

```json
{
  "name": "Lalbazar Police Station",
  "district_id": "eyJpdiI6..."
}
```

**Response `201 Created`:**

```json
{
  "data": { "..." : "..." },
  "message": "Police Station added successfully."
}
```

---

#### 12.4 Get Police Station

```
GET /api/v1/master-data/police-stations/{policeStation}
```

**Response `200 OK`:** [PoliceStationResource](#policestationresource)

---

#### 12.5 Update Police Station

```
PATCH /api/v1/master-data/police-stations/{policeStation}
```

At least one of `name` or `district_id` must be provided.

**Request Body (all fields optional, at least one required):**

| Field         | Type   | Required | Validation                                                                             |
| ------------- | ------ | -------- | -------------------------------------------------------------------------------------- |
| `name`        | string | No       | Max 200 characters, case-insensitive unique within district (excluding current)         |
| `district_id` | string | No       | Encrypted ID, must reference an existing active district                               |

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Police Station updated successfully."
}
```

---

#### 12.6 Lock Police Station

```
PATCH /api/v1/master-data/police-stations/{policeStation}/lock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Police Station locked successfully."
}
```

---

#### 12.7 Unlock Police Station

```
PATCH /api/v1/master-data/police-stations/{policeStation}/unlock
```

**Response `200 OK`:**

```json
{
  "data": { "..." : "..." },
  "message": "Police Station unlocked successfully."
}
```

---

## Resource Schemas

All resource IDs are encrypted opaque strings. The `is_locked` field is derived from the integer `status` field (status `0` = locked, status `1` = active/unlocked).

---

### StaffUserResource

```json
{
  "id": "integer",
  "name": "string",
  "mobile": "string (10-15 digits)",
  "role": {
    "id": "integer (1=Super Admin, 2=Operator)",
    "name": "string"
  }
}
```

---

### RecruitmentResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### DisciplineResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "recruitment_id": "string (encrypted)",
  "recruitment": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### QualificationResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "recruitment_id": "string (encrypted)",
  "recruitment": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### ExperienceResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "recruitment_id": "string (encrypted)",
  "recruitment": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### AgeRelaxationResource

```json
{
  "id": "string (encrypted)",
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### CasteCommunityResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### CasteIssuingAuthorityResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)"
}
```

---

### SubdivisionResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "district_id": "string (encrypted)",
  "district": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)",
  "updated_at": "string | null",
  "updated_by": "string (encrypted staff ID) | null"
}
```

---

### BlockMunicipalityResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "type": "string (Block | Municipality)",
  "district_id": "string (encrypted)",
  "district": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "subdivision_id": "string (encrypted) | null",
  "subdivision": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)",
  "updated_at": "string | null",
  "updated_by": "string (encrypted staff ID) | null"
}
```

---

### PoliceStationResource

```json
{
  "id": "string (encrypted)",
  "name": "string",
  "district_id": "string (encrypted)",
  "district": {
    "id": "string (encrypted)",
    "name": "string"
  },
  "status": "integer (0=locked, 1=active)",
  "is_locked": "boolean",
  "created_at": "string (YYYY-MM-DD HH:MM:SS)",
  "created_by": "string (encrypted staff ID)",
  "updated_at": "string | null",
  "updated_by": "string (encrypted staff ID) | null"
}
```

---

## Endpoint Summary

| #  | Method  | Endpoint                                                           | Auth | Description                      |
| -- | ------- | ------------------------------------------------------------------ | ---- | -------------------------------- |
| 1  | `GET`   | `/api/v1/status`                                                   | No   | Health check                     |
| 2  | `POST`  | `/api/v1/staff/auth/request-otp`                                   | No   | Request OTP challenge            |
| 3  | `POST`  | `/api/v1/staff/auth/login`                                         | No   | Verify OTP and login             |
| 4  | `GET`   | `/api/v1/staff/auth/details`                                       | Yes  | Get authenticated user           |
| 5  | `GET`   | `/api/user`                                                        | Yes  | Get authenticated user (alias)   |
| 6  | `POST`  | `/api/v1/staff/auth/logout`                                        | Yes  | Logout                           |
| 7  | `GET`   | `/api/v1/master-data/recruitments`                                 | Yes  | List recruitments                |
| 8  | `POST`  | `/api/v1/master-data/recruitments`                                 | Yes  | Create recruitment               |
| 9  | `PATCH` | `/api/v1/master-data/recruitments/{id}/lock`                       | Yes  | Lock recruitment                 |
| 10 | `PATCH` | `/api/v1/master-data/recruitments/{id}/unlock`                     | Yes  | Unlock recruitment               |
| 11 | `GET`   | `/api/v1/master-data/disciplines`                                  | Yes  | List disciplines                 |
| 12 | `POST`  | `/api/v1/master-data/disciplines`                                  | Yes  | Create discipline                |
| 13 | `GET`   | `/api/v1/master-data/disciplines/{id}`                             | Yes  | Get discipline                   |
| 14 | `PATCH` | `/api/v1/master-data/disciplines/{id}`                             | Yes  | Update discipline                |
| 15 | `PATCH` | `/api/v1/master-data/disciplines/{id}/lock`                        | Yes  | Lock discipline                  |
| 16 | `PATCH` | `/api/v1/master-data/disciplines/{id}/unlock`                      | Yes  | Unlock discipline                |
| 17 | `GET`   | `/api/v1/master-data/qualifications`                               | Yes  | List qualifications              |
| 18 | `POST`  | `/api/v1/master-data/qualifications`                               | Yes  | Create qualification             |
| 19 | `GET`   | `/api/v1/master-data/qualifications/{id}`                          | Yes  | Get qualification                |
| 20 | `PATCH` | `/api/v1/master-data/qualifications/{id}`                          | Yes  | Update qualification             |
| 21 | `PATCH` | `/api/v1/master-data/qualifications/{id}/lock`                     | Yes  | Lock qualification               |
| 22 | `PATCH` | `/api/v1/master-data/qualifications/{id}/unlock`                   | Yes  | Unlock qualification             |
| 23 | `GET`   | `/api/v1/master-data/experiences`                                  | Yes  | List experiences                 |
| 24 | `POST`  | `/api/v1/master-data/experiences`                                  | Yes  | Create experience                |
| 25 | `GET`   | `/api/v1/master-data/experiences/{id}`                             | Yes  | Get experience                   |
| 26 | `PATCH` | `/api/v1/master-data/experiences/{id}`                             | Yes  | Update experience                |
| 27 | `PATCH` | `/api/v1/master-data/experiences/{id}/lock`                        | Yes  | Lock experience                  |
| 28 | `PATCH` | `/api/v1/master-data/experiences/{id}/unlock`                      | Yes  | Unlock experience                |
| 29 | `GET`   | `/api/v1/master-data/age-relaxations`                              | Yes  | List age relaxations             |
| 30 | `POST`  | `/api/v1/master-data/age-relaxations`                              | Yes  | Create age relaxation            |
| 31 | `GET`   | `/api/v1/master-data/age-relaxations/{id}`                         | Yes  | Get age relaxation               |
| 32 | `PATCH` | `/api/v1/master-data/age-relaxations/{id}`                         | Yes  | Update age relaxation            |
| 33 | `PATCH` | `/api/v1/master-data/age-relaxations/{id}/lock`                    | Yes  | Lock age relaxation              |
| 34 | `PATCH` | `/api/v1/master-data/age-relaxations/{id}/unlock`                  | Yes  | Unlock age relaxation            |
| 35 | `GET`   | `/api/v1/master-data/caste-communities`                            | Yes  | List caste communities           |
| 36 | `GET`   | `/api/v1/master-data/caste-communities/caste-options`              | Yes  | Get caste enum options           |
| 37 | `POST`  | `/api/v1/master-data/caste-communities`                            | Yes  | Create caste community           |
| 38 | `GET`   | `/api/v1/master-data/caste-communities/{id}`                       | Yes  | Get caste community              |
| 39 | `PATCH` | `/api/v1/master-data/caste-communities/{id}`                       | Yes  | Update caste community           |
| 40 | `PATCH` | `/api/v1/master-data/caste-communities/{id}/lock`                  | Yes  | Lock caste community             |
| 41 | `PATCH` | `/api/v1/master-data/caste-communities/{id}/unlock`                | Yes  | Unlock caste community           |
| 42 | `GET`   | `/api/v1/master-data/caste-issuing-authorities`                    | Yes  | List caste issuing authorities   |
| 43 | `POST`  | `/api/v1/master-data/caste-issuing-authorities`                    | Yes  | Create caste issuing authority   |
| 44 | `GET`   | `/api/v1/master-data/caste-issuing-authorities/{id}`               | Yes  | Get caste issuing authority      |
| 45 | `PATCH` | `/api/v1/master-data/caste-issuing-authorities/{id}`               | Yes  | Update caste issuing authority   |
| 46 | `PATCH` | `/api/v1/master-data/caste-issuing-authorities/{id}/lock`          | Yes  | Lock caste issuing authority     |
| 47 | `PATCH` | `/api/v1/master-data/caste-issuing-authorities/{id}/unlock`        | Yes  | Unlock caste issuing authority   |
| 48 | `GET`   | `/api/v1/master-data/subdivisions`                                 | Yes  | List subdivisions                |
| 49 | `GET`   | `/api/v1/master-data/subdivisions/district-options`                | Yes  | Get district options             |
| 50 | `POST`  | `/api/v1/master-data/subdivisions`                                 | Yes  | Create subdivision               |
| 51 | `GET`   | `/api/v1/master-data/subdivisions/{id}`                            | Yes  | Get subdivision                  |
| 52 | `PATCH` | `/api/v1/master-data/subdivisions/{id}`                            | Yes  | Update subdivision               |
| 53 | `PATCH` | `/api/v1/master-data/subdivisions/{id}/lock`                       | Yes  | Lock subdivision                 |
| 54 | `PATCH` | `/api/v1/master-data/subdivisions/{id}/unlock`                     | Yes  | Unlock subdivision               |
| 55 | `GET`   | `/api/v1/master-data/block-municipalities`                         | Yes  | List block municipalities        |
| 56 | `GET`   | `/api/v1/master-data/block-municipalities/district-options`        | Yes  | Get district options             |
| 57 | `GET`   | `/api/v1/master-data/block-municipalities/subdivision-options`     | Yes  | Get subdivision options          |
| 58 | `POST`  | `/api/v1/master-data/block-municipalities`                         | Yes  | Create block municipality        |
| 59 | `GET`   | `/api/v1/master-data/block-municipalities/{id}`                    | Yes  | Get block municipality           |
| 60 | `PATCH` | `/api/v1/master-data/block-municipalities/{id}`                    | Yes  | Update block municipality        |
| 61 | `PATCH` | `/api/v1/master-data/block-municipalities/{id}/lock`               | Yes  | Lock block municipality          |
| 62 | `PATCH` | `/api/v1/master-data/block-municipalities/{id}/unlock`             | Yes  | Unlock block municipality        |
| 63 | `GET`   | `/api/v1/master-data/police-stations`                              | Yes  | List police stations             |
| 64 | `GET`   | `/api/v1/master-data/police-stations/district-options`             | Yes  | Get district options             |
| 65 | `POST`  | `/api/v1/master-data/police-stations`                              | Yes  | Create police station            |
| 66 | `GET`   | `/api/v1/master-data/police-stations/{id}`                         | Yes  | Get police station               |
| 67 | `PATCH` | `/api/v1/master-data/police-stations/{id}`                         | Yes  | Update police station            |
| 68 | `PATCH` | `/api/v1/master-data/police-stations/{id}/lock`                    | Yes  | Lock police station              |
| 69 | `PATCH` | `/api/v1/master-data/police-stations/{id}/unlock`                  | Yes  | Unlock police station            |
