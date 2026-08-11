<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Tapehouse</title></head>
<body>
<form method="POST" action="/login">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    @error('email')<p role="alert">{{ $message }}</p>@enderror
    <button type="submit">Sign in</button>
</form>
</body>
</html>
