import subprocess
import sys
import os

print("=" * 50)
print("  SMART LOCK - FACE RECOGNITION SYSTEM")
print("=" * 50)
print()
print("Starting backend (FastAPI) and frontend (Laravel)...")
print()

# Run FastAPI backend
backend_dir = os.path.join(os.path.dirname(__file__), 'backend')
os.chdir(backend_dir)

print("[1/2] Starting FastAPI backend at http://127.0.0.1:5000 ...")
backend_proc = subprocess.Popen(
    [sys.executable, 'app.py'],
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True
)

# Run Laravel frontend
project_dir = os.path.dirname(__file__)
os.chdir(project_dir)

print("[2/2] Starting Laravel frontend at http://127.0.0.1:8000 ...")
frontend_proc = subprocess.Popen(
    ['php', 'artisan', 'serve', '--port=8000'],
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True
)

print()
print("=" * 50)
print("  SYSTEM IS RUNNING!")
print("  Frontend: http://127.0.0.1:8000")
print("  Backend:  http://127.0.0.1:5000")
print("=" * 50)
print()
print("Press Ctrl+C to stop both servers.")

try:
    backend_proc.wait()
except KeyboardInterrupt:
    print("\nShutting down...")
    backend_proc.terminate()
    frontend_proc.terminate()
    print("Servers stopped.")
    sys.exit(0)