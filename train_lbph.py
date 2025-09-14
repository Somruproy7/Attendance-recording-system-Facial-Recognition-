import os
import json
import cv2
import numpy as np

ROOT = os.path.dirname(__file__)
IMAGES_DIR = os.path.join(ROOT, 'uploads', 'profiles')
MODEL_PATH = os.path.join(ROOT, 'lbph_model.yml')
LABELS_PATH = os.path.join(ROOT, 'lbph_labels.json')
CENTROIDS_PATH = os.path.join(ROOT, 'lbph_centroids.json')

def detect_face(gray):
    cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
    face_cascade = cv2.CascadeClassifier(cascade_path)
    faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(80, 80))
    if len(faces) == 0:
        return None
    x, y, w, h = max(faces, key=lambda f: f[2]*f[3])
    return gray[y:y+h, x:x+w]

def main():
    images = []
    labels = []
    label_to_student = {}
    next_label = 0

    if not os.path.isdir(IMAGES_DIR):
        print('No images directory found at', IMAGES_DIR)
        return

    for fname in os.listdir(IMAGES_DIR):
        if not fname.lower().endswith(('.png', '.jpg', '.jpeg')):
            continue
        # Extract first number in filename as student_id
        import re
        m = re.findall(r"\d+", fname)
        if not m:
            continue
        student_id = int(m[0])
        path = os.path.join(IMAGES_DIR, fname)
        bgr = cv2.imread(path)
        if bgr is None:
            continue
        gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
        # Normalize lighting
        gray = cv2.equalizeHist(gray)
        face = detect_face(gray)
        if face is None:
            continue
        face = cv2.resize(face, (200, 200))
        # Augment: original + slight blur + horizontal flip
        augments = [face,
                    cv2.GaussianBlur(face, (3,3), 0),
                    cv2.flip(face, 1)]

        if student_id not in label_to_student.values():
            label = next_label
            label_to_student[str(label)] = student_id
            next_label += 1
        else:
            # find existing label
            label = int([k for k, v in label_to_student.items() if v == student_id][0])

        for aug in augments:
            images.append(aug)
            labels.append(label)

    if not images:
        print('No faces found to train.')
        return

    recognizer = cv2.face.LBPHFaceRecognizer_create(radius=2, neighbors=16, grid_x=8, grid_y=8)
    recognizer.train(images, np.array(labels))
    recognizer.write(MODEL_PATH)
    with open(LABELS_PATH, 'w', encoding='utf-8') as f:
        json.dump(label_to_student, f)

    # Also compute simple per-student average face (centroid) for a JSON-only fallback matcher
    by_label = {}
    for img, lab in zip(images, labels):
        by_label.setdefault(lab, []).append(img.astype('float32')/255.0)
    centroids = {str(lab): (np.mean(stack, axis=0)).tolist() for lab, stack in ((lab, np.stack(arr)) for lab, arr in by_label.items())}
    with open(CENTROIDS_PATH, 'w', encoding='utf-8') as f:
        json.dump({ 'label_to_student': label_to_student, 'centroids': centroids }, f)

    print('Trained LBPH model with', len(images), 'images for', len(set(labels)), 'students.')

if __name__ == '__main__':
    main()


