@component('mail::message')
# Welcome to StudyNest, {{ $studentName }}!

Your student account has been created. Here are your login credentials:

@component('mail::panel')
**Student ID:** {{ $studentNumber }}<br>
**Temporary Password:** {{ $password }}
@endcomponent

@component('mail::button', ['url' => $loginUrl])
Log In to StudyNest
@endcomponent

**Login instructions:**

1. Go to the StudyNest login page.
2. Enter your Student ID and temporary password above.
3. You will be required to set a new password the first time you log in.
4. Keep your credentials private — do not share them with anyone.

If you were not expecting this email, please contact your school administrator.

Thanks,<br>
The StudyNest Team
@endcomponent