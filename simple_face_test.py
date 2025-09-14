import cv2
import os

def simple_face_recognition_test():
    print("Starting simple face recognition test...")
    
    # Load face cascade
    face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
    if face_cascade.empty():
        print("ERROR: Could not load face cascade classifier")
        return
    
    # Try to open camera
    print("Attempting to open camera...")
    cap = cv2.VideoCapture(0)
    
    if not cap.isOpened():
        print("ERROR: Could not open camera 0")
        # Try camera 1
        cap = cv2.VideoCapture(1)
        if not cap.isOpened():
            print("ERROR: Could not open camera 1 either")
            return
        else:
            print("Camera 1 opened successfully")
    else:
        print("Camera 0 opened successfully")
    
    # Set camera properties
    cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)
    
    print("Starting video loop...")
    print("Controls: Press 'q' to quit, 's' to save frame")
    
    frame_count = 0
    
    while True:
        ret, frame = cap.read()
        
        if not ret:
            print("ERROR: Failed to read frame")
            break
        
        frame_count += 1
        
        # Convert to grayscale for face detection
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        
        # Detect faces
        faces = face_cascade.detectMultiScale(gray, 1.1, 4)
        
        # Draw rectangles around faces
        for (x, y, w, h) in faces:
            cv2.rectangle(frame, (x, y), (x+w, y+h), (255, 0, 0), 2)
            cv2.putText(frame, 'Face Detected', (x, y-10), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (255, 0, 0), 2)
        
        # Add frame counter
        cv2.putText(frame, f'Frame: {frame_count}', (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)
        cv2.putText(frame, f'Faces: {len(faces)}', (10, 70), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)
        cv2.putText(frame, "Press 'q' to quit", (10, frame.shape[0] - 20), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255, 255), 2)
        
        # Display the frame
        cv2.imshow('Simple Face Recognition Test', frame)
        
        # Check for key press
        key = cv2.waitKey(1) & 0xFF
        if key == ord('q'):
            print("User pressed 'q' - exiting")
            break
        elif key == ord('s'):
            filename = f'test_frame_{frame_count}.jpg'
            cv2.imwrite(filename, frame)
            print(f"Frame saved as {filename}")
    
    # Cleanup
    cap.release()
    cv2.destroyAllWindows()
    print("Test completed")

if __name__ == "__main__":
    simple_face_recognition_test()
