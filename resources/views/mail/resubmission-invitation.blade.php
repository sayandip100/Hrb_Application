<!doctype html>
<html lang="en"><body>
<p>Dear Candidate,</p>
<p>A resubmission window has been opened for advertisement <strong>{{ $advertisementNumber }}</strong>, post <strong>{{ $postName }}</strong>.</p>
<p>You may correct the rejected information/documents through your candidate login from <strong>{{ $startsAt }}</strong> to <strong>{{ $endsAt }}</strong> (Asia/Kolkata).</p>
@if(filled($reason))<p>{{ $reason }}</p>@endif
<p>Please submit each rejected section individually. If you do not resubmit during this period, the relevant application status will remain unchanged.</p>
<p>West Bengal Health Recruitment Board (WBHRB)</p>
</body></html>
