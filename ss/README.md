# Face Recognition System

This system compares webcam captures with saved reference photos to identify if the same person is detected.

## Setup

1. Install dependencies:
```bash
pip install opencv-python face-recognition numpy
```

2. Place your reference photos in the same directory as the script
   - Supported formats: .jpg, .jpeg, .png, .bmp
   - Current photos: 1.png, 2.jpeg, 3.jpeg, 4.jpeg

## Usage

Run the face recognition app:
```bash
python face_recognition_app.py
```

### Controls:
- **Q**: Quit the application
- **S**: Capture current frame and check for matches

### How it works:
1. Loads all photos from current directory as reference faces
2. Opens webcam for real-time face detection
3. Compares detected faces with reference photos
4. Shows "MATCH FOUND!" in green if face matches any reference photo
5. Shows "No match" in red if face doesn't match any reference

### Results:
- **SUCCESS**: Face matches one of your saved photos
- **NO MATCH**: Face not recognized from saved photos

## Photo Path Configuration

The system automatically loads photos from the current directory (`.`). To change the photo location, modify the `PHOTOS_DIR` variable in `face_recognition_app.py`:

```python
PHOTOS_DIR = "path/to/your/photos"  # Change this path as needed
```
