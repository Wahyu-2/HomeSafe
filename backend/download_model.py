import os
import urllib.request
import urllib.error
import socket
import sys

def download_file(url, path, timeout=300):
    """Download file with timeout and error handling."""
    if os.path.exists(path):
        print(f"File already exists at {path}. Skipping download.")
        return True
        
    print(f"Downloading from {url} to {path}...")
    try:
        def report_hook(block_num, block_size, total_size):
            read_so_far = block_num * block_size
            if total_size > 0:
                percent = read_so_far * 1e2 / total_size
                s = f"\rProgress: {percent:.1f}% ({read_so_far / (1024*1024):.2f} MB of {total_size / (1024*1024):.2f} MB)"
                sys.stdout.write(s)
                sys.stdout.flush()
            else:
                sys.stdout.write(f"\rDownloaded {read_so_far / (1024*1024):.2f} MB")
                sys.stdout.flush()
        
        # Set socket timeout
        socket.setdefaulttimeout(timeout)
        urllib.request.urlretrieve(url, path, reporthook=report_hook)
        print("\nDownload completed successfully!")
        return True
    except urllib.error.URLError as e:
        print(f"\nNetwork error downloading file: {e}")
        return False
    except socket.timeout:
        print(f"\nDownload timed out after {timeout} seconds")
        return False
    except Exception as e:
        print(f"\nError downloading file: {e}")
        return False
    finally:
        # Reset socket timeout
        socket.setdefaulttimeout(None)

def download_models():
    models_dir = "models"
    if not os.path.exists(models_dir):
        print(f"Creating directory: {models_dir}")
        os.makedirs(models_dir)
        
    # 1. SFace ONNX Model (MobileFaceNet Backbone)
    sface_url = "https://huggingface.co/opencv/face_recognition_sface/resolve/main/face_recognition_sface_2021dec.onnx"
    sface_path = os.path.join(models_dir, "face_recognition_sface_2021dec.onnx")
    print("\n--- SFace ONNX Model ---")
    download_file(sface_url, sface_path)
    
    # 2. MediaPipe Face Detector TFLite Model
    mediapipe_url = "https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite"
    mediapipe_path = os.path.join(models_dir, "blaze_face_short_range.tflite")
    print("\n--- MediaPipe Face Detector TFLite Model ---")
    download_file(mediapipe_url, mediapipe_path)

if __name__ == "__main__":
    download_models()
