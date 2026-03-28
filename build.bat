@echo off
setlocal
powershell -ExecutionPolicy Bypass -File "%~dp0build\build.ps1"
exit /b %ERRORLEVEL%
